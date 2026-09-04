<!DOCTYPE html>
<html lang="hr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Trenutno offline</title>
    <style>
        :root {
            --bg: #f5f6f8;
            --card: #ffffff;
            --text: #0f172a;
            --muted: #475569;
            --line: #dbe2ea;
            --accent: #111827;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            font-family: "Risingsun", Arial, Helvetica, sans-serif;
            color: var(--text);
            background:
                linear-gradient(180deg, rgba(255,255,255,0.95), rgba(245,246,248,0.96)),
                radial-gradient(1200px 480px at 12% -10%, rgba(15,23,42,0.08), transparent),
                radial-gradient(900px 400px at 100% 100%, rgba(2,6,23,0.06), transparent),
                var(--bg);
        }
        .panel {
            width: min(760px, 100%);
            border: 1px solid var(--line);
            background: var(--card);
            box-shadow: 0 24px 70px -42px rgba(15, 23, 42, 0.45);
        }
        .top {
            height: 8px;
            background: linear-gradient(90deg, #020617 0%, #111827 45%, #1f2937 100%);
        }
        .inner {
            padding: 34px 30px;
        }
        .kicker {
            margin: 0 0 10px;
            font-size: 12px;
            letter-spacing: .18em;
            text-transform: uppercase;
            color: #64748b;
        }
        h1 {
            margin: 0 0 10px;
            font-size: clamp(32px, 6vw, 56px);
            line-height: 1;
            letter-spacing: .01em;
        }
        p {
            margin: 0;
            max-width: 56ch;
            color: var(--muted);
            font-size: 18px;
            line-height: 1.5;
        }
        .actions {
            margin-top: 22px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 0 16px;
            text-decoration: none;
            border: 1px solid var(--accent);
            background: var(--accent);
            color: #fff;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
        }
        .btn-ghost {
            background: #fff;
            color: var(--accent);
            border-color: var(--line);
        }
    </style>
</head>
<body>
    <section class="panel" role="main" aria-labelledby="offline-title">
        <div class="top"></div>
        <div class="inner">
            <p class="kicker">Maintenance Mode</p>
            <h1 id="offline-title">Trenutno offline</h1>
            <p>Stranica je trenutno offline zbog održavanja. Vraćamo se uskoro.</p>

        </div>
    </section>
</body>
</html>
