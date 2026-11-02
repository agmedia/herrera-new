"""Safe local checks; never invokes a hosting cron, application or EPREL API."""

import os
from pathlib import Path
import re
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]
DEPLOY = ROOT / "scripts/deploy-staging.sh"
WORKER = ROOT / "scripts/run-staging-eprel-worker.sh"
MARKER = b" # HERRERA-STAGING-EPREL-WORKER\n"


class StagingEprelWorkerTest(unittest.TestCase):
    def render_cron(self, before):
        cron_section = DEPLOY.read_text().split("# Preserve every unrelated cron byte", 1)[1]
        match = re.search(
            r'"\$php_bin" -r \'(.*?)\' "\$backup_root/crontab.before" "\$backup_root/crontab.after"',
            cron_section,
            re.S,
        )
        self.assertIsNotNone(match)
        with tempfile.TemporaryDirectory() as folder:
            old, new = Path(folder) / "before", Path(folder) / "after"
            old.write_bytes(before)
            subprocess.run(["php", "-r", match.group(1), str(old), str(new)], check=True)
            return new.read_bytes()

    def test_cron_preserves_unrelated_bytes_and_is_idempotent(self):
        before = b'MAILTO="ops@example.test"\n# Keep $HOME and 10% literally\n15 2 * * * /home/herrera/production.sh\n'
        after = self.render_cron(before)
        self.assertTrue(after.startswith(before))
        self.assertEqual(1, after.count(MARKER))
        self.assertEqual(after, self.render_cron(after))

    def test_cron_replaces_only_owned_line_and_handles_empty_table(self):
        prefix = b"# User comment\r\n"
        suffix = b"7 * * * * /home/herrera/another-job.sh\r\n"
        before = prefix + b"* * * * * /old/worker.sh" + MARKER + suffix
        after = self.render_cron(before)
        self.assertTrue(after.startswith(prefix + suffix))
        self.assertNotIn(b"/old/worker.sh", after)
        self.assertEqual(1, after.count(MARKER))
        self.assertTrue(self.render_cron(b"").endswith(MARKER))

    def test_real_scripts_parse_and_local_worker_refuses_hosting_access(self):
        for script in [DEPLOY, WORKER]:
            subprocess.run(["bash", "-n", str(script)], check=True)
        result = subprocess.run(["bash", str(WORKER), "--check"], capture_output=True, text=True)
        self.assertEqual(1, result.returncode)
        self.assertIn("Use the registered cPanel checkout", result.stderr)

    def test_rollback_restores_our_change_but_preserves_concurrent_cron_edits(self):
        source = DEPLOY.read_text()
        snapshot = source.split("snapshot_crontab() {", 1)[1].split('\nsnapshot_crontab "$backup_root/crontab.before"', 1)[0]
        rollback = source.split("rollback() {", 1)[1].split("\ntrap rollback ERR", 1)[0]
        with tempfile.TemporaryDirectory() as folder:
            base = Path(folder)
            state, backup = base / "state", base / "backup"
            backup.mkdir()
            before = b"# Existing production job\n"
            after = self.render_cron(before)
            (backup / "crontab.before").write_bytes(before)
            (backup / "crontab.after").write_bytes(after)
            stub = base / "crontab"
            stub.write_text('#!/bin/bash\nif [[ "$1" == -l ]]; then cat "$TEST_CRON_STATE"; elif [[ "$1" == -r ]]; then rm -f "$TEST_CRON_STATE"; else cp "$1" "$TEST_CRON_STATE"; fi\n')
            stub.chmod(0o755)
            env = dict(os.environ, PATH=str(base) + ":/usr/bin:/bin", TEST_CRON_STATE=str(state))
            code = 'backup_root="$1"; cron_changed=true; applied=false;\nsnapshot_crontab() {' + snapshot + '\nrollback() {' + rollback + '\nrollback\n'
            for current, expected in [(after, before), (after + b"# New unrelated edit\n", after + b"# New unrelated edit\n")]:
                state.write_bytes(current)
                result = subprocess.run(["bash", "-c", code, "test", str(backup)], env=env, capture_output=True, text=True)
                self.assertEqual(1, result.returncode)
                self.assertEqual(expected, state.read_bytes())
            state.write_bytes(after)
            (backup / "crontab.before.absent").touch()
            result = subprocess.run(["bash", "-c", code, "test", str(backup)], env=env, capture_output=True, text=True)
            self.assertEqual(1, result.returncode)
            self.assertFalse(state.exists())

    def test_worker_checks_safety_and_configuration_before_bounded_queue(self):
        with tempfile.TemporaryDirectory() as folder:
            base = Path(folder).resolve()
            checkout, target, private = base / "checkout", base / "target", base / "private"
            tools = base / "bin"
            for directory in [checkout / "scripts", target / "vendor", target / "storage/framework", private, tools]:
                directory.mkdir(parents=True, exist_ok=True)
            (target / ".env").touch()
            (target / "vendor/autoload.php").touch()
            marker = target / ".herrera-deploy-revision"
            marker.write_text("test-revision\n")
            log = base / "calls"
            stubs = {
                "id": '#!/bin/bash\nprintf "%s\\n" herrera\n',
                "git": '#!/bin/bash\nif [[ "$*" == *"rev-parse"* ]]; then printf "%s\\n" test-revision; fi\n',
                "flock": '#!/bin/bash\n[[ "${TEST_LOCK_BUSY:-0}" != 1 ]]\n',
                "php": '#!/bin/bash\nprintf "%s\\n" "$*" >> "$TEST_CALL_LOG"\nif [[ "$1" == -r ]]; then exit "${TEST_CONFIG_STATUS:-0}"; fi\n',
            }
            for name, content in stubs.items():
                path = tools / name
                path.write_text(content)
                path.chmod(0o755)
            source = WORKER.read_text()
            for old, new in [
                ("/home/herrera/repositories/herrera-new", str(checkout)),
                ("/home/herrera/herrera.herrera.hr", str(target)),
                ("/home/herrera/.herrera-staging-backups", str(private)),
                ("/opt/cpanel/ea-php84/root/usr/bin/php", str(tools / "php")),
            ]:
                source = source.replace(old, new)
            fixture = checkout / "scripts/run-staging-eprel-worker.sh"
            fixture.write_text(source)
            env = dict(os.environ, PATH=str(tools) + ":/usr/bin:/bin", TEST_CALL_LOG=str(log))

            def run(*args, **overrides):
                log.unlink(missing_ok=True)
                result = subprocess.run(["bash", str(fixture), *args], env=dict(env, **overrides), capture_output=True, text=True)
                calls = log.read_text() if log.exists() else ""
                return result, calls

            result, calls = run("--check")
            self.assertEqual(0, result.returncode, result.stderr)
            self.assertIn("EPREL worker ready", result.stdout)
            self.assertNotIn("artisan queue:work", calls)
            result, calls = run(TEST_CONFIG_STATUS="3")
            self.assertEqual(0, result.returncode)
            self.assertNotIn("artisan queue:work", calls)
            result, calls = run(TEST_CONFIG_STATUS="1")
            self.assertEqual(1, result.returncode)
            self.assertNotIn("artisan queue:work", calls)
            result, calls = run()
            self.assertEqual(0, result.returncode, result.stderr)
            self.assertIn("artisan queue:work eprel --queue=eprel --stop-when-empty --max-time=50 --timeout=600 --sleep=3 --tries=3 --no-interaction", calls)

            result, calls = run("--check", TEST_LOCK_BUSY="1")
            self.assertEqual(0, result.returncode)
            self.assertEqual("", calls)
            marker.write_text("not-deployed\n")
            result, calls = run("--check")
            self.assertEqual(0, result.returncode)
            self.assertEqual("", calls)
            marker.write_text("test-revision\n")
            (target / "storage/framework/down").touch()
            result, calls = run("--check")
            self.assertEqual(0, result.returncode)
            self.assertEqual("", calls)


if __name__ == "__main__":
    unittest.main()
