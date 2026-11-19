(() => {
    const positiveInteger = (value, fallback) => {
        const parsed = Number(value);
        return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : fallback;
    };

    const limits = (input) => {
        const minimum = positiveInteger(input?.min || input?.dataset?.quantityMin, 1);
        const step = positiveInteger(input?.step || input?.dataset?.quantityStep, 1);
        const rawMaximum = Number.parseInt(String(input?.max || input?.dataset?.quantityMax || 999), 10);
        const available = Number.isFinite(rawMaximum) && rawMaximum >= minimum;
        const maximum = available ? minimum + Math.floor((Math.min(999, rawMaximum) - minimum) / step) * step : minimum;
        return { minimum, step, maximum: Math.max(minimum, maximum), available };
    };

    const normalize = (input, value) => {
        const { minimum, step, maximum } = limits(input);
        const requested = Math.max(minimum, positiveInteger(value, minimum));
        return Math.min(maximum, minimum + Math.ceil((requested - minimum) / step) * step);
    };

    window.ShopOrderQuantity = Object.freeze({
        limits,
        normalize,
        move: (input, direction) => normalize(input, normalize(input, input?.value) + (direction > 0 ? 1 : -1) * limits(input).step),
    });
})();
