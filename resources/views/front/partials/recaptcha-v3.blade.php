@once
    @push('scripts')
        <script src="https://www.google.com/recaptcha/api.js?render={{ $siteKey }}"></script>
        <script>
            (function () {
                document.querySelectorAll('[data-recaptcha-form]').forEach(function (form) {
                    if (form.dataset.recaptchaBound === '1') {
                        return;
                    }

                    form.dataset.recaptchaBound = '1';

                    form.addEventListener('submit', function (event) {
                        const tokenInput = form.querySelector('[data-recaptcha-token]');
                        const siteKey = form.dataset.recaptchaSiteKey;
                        const action = form.dataset.recaptchaAction || 'form_submit';

                        if (!tokenInput || !window.grecaptcha || !siteKey) {
                            return;
                        }

                        event.preventDefault();

                        grecaptcha.ready(function () {
                            grecaptcha.execute(siteKey, { action: action })
                                .then(function (token) {
                                    tokenInput.value = token || '';
                                    form.submit();
                                })
                                .catch(function () {
                                    tokenInput.value = '';
                                    form.submit();
                                });
                        });
                    });
                });
            }());
        </script>
    @endpush
@endonce
