document.addEventListener('DOMContentLoaded', function () {
    const fitModal = document.querySelector('[data-fit-finder-modal]');
    const openButtons = document.querySelectorAll('[data-fit-finder-open]');
    const form = document.querySelector('[data-product-detail-form]');
    if (!fitModal || !openButtons.length || !form) {
        return;
    }

    const sizeInputs = Array.from(form.querySelectorAll('input[name="product_option_value_id"][data-size-label]'));
    const linkedPrimarySelect = form.querySelector('[data-linked-option-primary]');
    const linkedSecondarySelect = form.querySelector('[data-linked-option-secondary]');
    const hasLinkedSelectors = !!(linkedPrimarySelect && linkedSecondarySelect);

    const closeButtons = fitModal.querySelectorAll('[data-fit-finder-close]');
    const helpToggleButton = fitModal.querySelector('[data-fit-finder-help-toggle]');
    const helpPanel = fitModal.querySelector('[data-fit-finder-help-panel]');
    const helpIconClosed = fitModal.querySelector('[data-fit-help-icon-closed]');
    const helpIconOpen = fitModal.querySelector('[data-fit-help-icon-open]');
    const steps = Array.from(fitModal.querySelectorAll('[data-fit-step]'));
    const timelineItems = Array.from(fitModal.querySelectorAll('[data-fit-timeline-item]'));
    const progress = fitModal.querySelector('[data-fit-finder-progress]');
    const nextButton = fitModal.querySelector('[data-fit-next]');
    const prevButton = fitModal.querySelector('[data-fit-prev]');
    const applyButton = fitModal.querySelector('[data-fit-apply]');

    const inputHeight = fitModal.querySelector('[data-fit-height]');
    const inputWeight = fitModal.querySelector('[data-fit-weight]');
    const inputAge = fitModal.querySelector('[data-fit-age]');
    const inputHeightValue = fitModal.querySelector('[data-fit-height-value]');
    const inputWeightValue = fitModal.querySelector('[data-fit-weight-value]');
    const inputAgeValue = fitModal.querySelector('[data-fit-age-value]');

    const resultSize = [
        fitModal.querySelector('[data-fit-finder-result-size="0"]'),
        fitModal.querySelector('[data-fit-finder-result-size="1"]'),
    ];
    const resultPercent = [
        fitModal.querySelector('[data-fit-finder-result-percent="0"]'),
        fitModal.querySelector('[data-fit-finder-result-percent="1"]'),
    ];
    const resultBar = [
        fitModal.querySelector('[data-fit-finder-result-bar="0"]'),
        fitModal.querySelector('[data-fit-finder-result-bar="1"]'),
    ];
    const resultRow = [
        fitModal.querySelector('[data-fit-finder-result-row="0"]'),
        fitModal.querySelector('[data-fit-finder-result-row="1"]'),
    ];
    const summary = fitModal.querySelector('[data-fit-finder-summary]');
    const textErrorHeight = String(fitModal.dataset.textErrorHeight || 'Invalid height');
    const textErrorWeight = String(fitModal.dataset.textErrorWeight || 'Invalid weight');
    const textErrorAge = String(fitModal.dataset.textErrorAge || 'Invalid age');
    const textStepTemplate = String(fitModal.dataset.textStepTemplate || 'Step __CURRENT__ of __TOTAL__');
    const textRecommendationReady = String(fitModal.dataset.textRecommendationReady || 'Recommendation ready');
    const textSummaryTemplate = String(fitModal.dataset.textSummaryTemplate || 'Recommended size is __SIZE__ with confidence __PERCENT__%.');
    const textCtaTemplate = String(fitModal.dataset.textCtaTemplate || 'Add size __SIZE__ to cart');
    const textTrigger = String(fitModal.dataset.textTrigger || 'Find size');
    const textSavedPrefix = String(fitModal.dataset.textSavedPrefix || 'Your size is');
    const fitSaveUrl = String(fitModal.dataset.fitSaveUrl || '');
    const fitProductId = Number(fitModal.dataset.fitProductId || 0);
    const initialSizeLabel = String(fitModal.dataset.fitInitialSize || '').trim().toUpperCase();
    const initialSizeSignature = String(fitModal.dataset.fitInitialSizeSignature || '').trim();
    const saveIndicator = fitModal.querySelector('[data-fit-save-indicator]');

    const state = {
        step: 0,
        fit: String(fitModal.dataset.fitInitialFit || 'average'),
        chest: String(fitModal.dataset.fitInitialChest || 'average'),
        belly: String(fitModal.dataset.fitInitialBelly || 'average'),
        savedSizeLabel: initialSizeLabel,
        recommendation: null,
    };

    const sizeRankMap = {
        XXS: 1,
        XS: 2,
        S: 3,
        M: 4,
        L: 5,
        XL: 6,
        XXL: 7,
        XXXL: 8,
        '4XL': 9,
        '5XL': 10,
    };

    const sizeOptions = (function () {
        if (sizeInputs.length >= 2) {
            return sizeInputs.map(function (input, index) {
                const label = String(input.dataset.sizeLabel || '').trim();
                const key = label.toUpperCase();
                const rank = Object.prototype.hasOwnProperty.call(sizeRankMap, key) ? sizeRankMap[key] : (index + 3);
                return { input, label, rank };
            });
        }

        if (hasLinkedSelectors) {
            return Array.from(linkedPrimarySelect.options)
                .filter(function (option) {
                    return String(option.value || '').trim() !== '';
                })
                .map(function (option, index) {
                    const label = String(option.textContent || '').trim();
                    const key = label.toUpperCase();
                    const rank = Object.prototype.hasOwnProperty.call(sizeRankMap, key) ? sizeRankMap[key] : (index + 3);
                    return {
                        input: {
                            __linked: true,
                            parentId: String(option.value || '').trim(),
                        },
                        label,
                        rank,
                    };
                });
        }

        return [];
    })();
    if (sizeOptions.length < 2) {
        return;
    }
    const currentSizeSignature = sizeOptions
        .map(function (option) {
            return String(option.label || '').trim().toUpperCase();
        })
        .filter(function (label) {
            return label !== '';
        })
        .sort()
        .join('|');
    state.savedSizeSignature = currentSizeSignature;

    const clamp = function (value, min, max) {
        return Math.max(min, Math.min(max, value));
    };

    const updateFitFinderOpenButtons = function (sizeLabel) {
        const cleaned = String(sizeLabel || '').trim().toUpperCase();
        openButtons.forEach(function (button) {
            if (cleaned === '') {
                button.textContent = textTrigger;
                return;
            }

            button.textContent = textSavedPrefix + ' ' + cleaned;
        });
    };

    const syncRangeValue = function (input, output) {
        if (!input || !output) {
            return;
        }
        output.textContent = String(input.value || '');
    };

    let persistTimer = null;
    let saveIndicatorTimer = null;

    const setSaveIndicator = function (status) {
        if (!saveIndicator) {
            return;
        }

        if (saveIndicatorTimer) {
            window.clearTimeout(saveIndicatorTimer);
            saveIndicatorTimer = null;
        }

        if (status === 'saving') {
            saveIndicator.textContent = 'Spremanje...';
            saveIndicator.classList.remove('text-emerald-700', 'text-rose-600', 'opacity-0');
            saveIndicator.classList.add('text-slate-500');
            return;
        }

        if (status === 'saved') {
            saveIndicator.textContent = 'Spremljeno';
            saveIndicator.classList.remove('text-slate-500', 'text-rose-600', 'opacity-0');
            saveIndicator.classList.add('text-emerald-700');
            saveIndicatorTimer = window.setTimeout(function () {
                saveIndicator.classList.add('opacity-0');
            }, 1200);
            return;
        }

        if (status === 'error') {
            saveIndicator.textContent = 'Greška spremanja';
            saveIndicator.classList.remove('text-slate-500', 'text-emerald-700', 'opacity-0');
            saveIndicator.classList.add('text-rose-600');
            saveIndicatorTimer = window.setTimeout(function () {
                saveIndicator.classList.add('opacity-0');
            }, 2200);
            return;
        }

        saveIndicator.classList.add('opacity-0');
    };

    const persistFitPreference = function (sizeLabel) {
        if (!fitSaveUrl || !fitProductId) {
            return;
        }

        const tokenInput = form.querySelector('input[name="_token"]');
        const token = tokenInput ? String(tokenInput.value) : '';
        if (!token) {
            return;
        }
        setSaveIndicator('saving');

        const payload = new URLSearchParams();
        const normalizedCandidate = String(sizeLabel || state.savedSizeLabel || '').trim().toUpperCase();
        const normalizedSizeLabel = state.savedSizeSignature === currentSizeSignature ? normalizedCandidate : '';
        payload.set('_token', token);
        payload.set('product_id', String(fitProductId));
        payload.set('size_label', normalizedSizeLabel);
        payload.set('size_signature', currentSizeSignature);
        payload.set('height', String(inputHeight.value || ''));
        payload.set('weight', String(inputWeight.value || ''));
        payload.set('age', String(inputAge.value || ''));
        payload.set('fit', String(state.fit || 'average'));
        payload.set('chest', String(state.chest || 'average'));
        payload.set('belly', String(state.belly || 'average'));

        if (typeof navigator.sendBeacon === 'function') {
            const blob = new Blob([payload.toString()], { type: 'application/x-www-form-urlencoded;charset=UTF-8' });
            navigator.sendBeacon(fitSaveUrl, blob);
            setSaveIndicator('saved');
            return;
        }

        fetch(fitSaveUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'Accept': 'application/json',
            },
            body: payload.toString(),
            credentials: 'same-origin',
            keepalive: true,
        })
            .then(function (response) {
                if (response && response.ok) {
                    setSaveIndicator('saved');
                    return;
                }
                setSaveIndicator('error');
            })
            .catch(function () {
                setSaveIndicator('error');
            });
    };

    const schedulePersist = function () {
        if (persistTimer) {
            window.clearTimeout(persistTimer);
        }

        persistTimer = window.setTimeout(function () {
            persistTimer = null;
            persistFitPreference(state.savedSizeLabel);
        }, 300);
    };

    const clearStepErrors = function () {
        const errors = fitModal.querySelectorAll('[data-fit-error]');
        errors.forEach(function (error) {
            error.textContent = '';
            error.classList.add('hidden');
        });
    };

    const setStepError = function (message) {
        const error = steps[state.step] ? steps[state.step].querySelector('[data-fit-error]') : null;
        if (!error) {
            return;
        }
        error.textContent = message;
        error.classList.remove('hidden');
    };

    const setPressed = function (attribute, value) {
        const buttons = fitModal.querySelectorAll('[' + attribute + ']');
        buttons.forEach(function (button) {
            const isActive = button.getAttribute(attribute) === value;
            button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
    };

    const validateCurrentStep = function () {
        clearStepErrors();

        if (state.step === 0) {
            const height = Number(inputHeight.value);
            const weight = Number(inputWeight.value);
            if (!Number.isFinite(height) || height < 130 || height > 230) {
                setStepError(textErrorHeight);
                return false;
            }
            if (!Number.isFinite(weight) || weight < 35 || weight > 220) {
                setStepError(textErrorWeight);
                return false;
            }
        }

        if (state.step === 1) {
            const age = Number(inputAge.value);
            if (!Number.isFinite(age) || age < 12 || age > 100) {
                setStepError(textErrorAge);
                return false;
            }
        }

        return true;
    };

    const calculateRecommendation = function () {
        const height = Number(inputHeight.value);
        const weight = Number(inputWeight.value);
        const age = Number(inputAge.value);
        const heightMeters = height / 100;
        const bmi = weight / (heightMeters * heightMeters);

        let target = 4.2;
        if (bmi < 20) target -= 1;
        else if (bmi < 23) target -= 0.4;
        else if (bmi < 26) target += 0.2;
        else if (bmi < 29) target += 0.8;
        else target += 1.3;

        if (height > 188) target += 0.2;
        if (height < 170) target -= 0.2;
        if (age > 45) target += 0.2;
        if (age > 60) target += 0.25;

        if (state.fit === 'tighter') target -= 0.5;
        if (state.fit === 'looser') target += 0.5;

        if (state.chest === 'slimmer') target -= 0.2;
        if (state.chest === 'broader') target += 0.35;

        if (state.belly === 'flatter') target -= 0.15;
        if (state.belly === 'rounder') target += 0.35;

        const scored = sizeOptions
            .map(function (option) {
                const distance = Math.abs(option.rank - target);
                const score = Math.max(25, 100 - (distance * 22));
                return { option, score };
            })
            .sort(function (a, b) {
                return b.score - a.score;
            });

        const first = scored[0];
        const second = scored[1] || scored[0];
        const firstPercent = clamp(Math.round(68 + (first.score - second.score) * 0.9), 55, 93);
        let secondPercent = clamp(Math.round((100 - firstPercent) + 22), 35, 88);
        if (secondPercent >= firstPercent) {
            secondPercent = Math.max(30, firstPercent - 8);
        }

        state.recommendation = {
            primary: { label: first.option.label, input: first.option.input, percent: firstPercent },
            secondary: { label: second.option.label, input: second.option.input, percent: secondPercent },
        };
    };

    const applyRecommendedInput = function (inputRef) {
        if (!inputRef) {
            return false;
        }

        if (inputRef.__linked) {
            if (!hasLinkedSelectors) {
                return false;
            }

            const parentId = String(inputRef.parentId || '').trim();
            if (parentId === '') {
                return false;
            }

            linkedPrimarySelect.value = parentId;
            linkedPrimarySelect.dispatchEvent(new Event('change', { bubbles: true }));
            return true;
        }

        if (inputRef.checked) {
            return true;
        }

        inputRef.checked = true;
        inputRef.dispatchEvent(new Event('change', { bubbles: true }));
        return true;
    };

    const renderResults = function () {
        calculateRecommendation();
        if (!state.recommendation) {
            return;
        }

        resultSize[0].textContent = state.recommendation.primary.label;
        resultPercent[0].textContent = state.recommendation.primary.percent + '%';
        resultBar[0].style.width = state.recommendation.primary.percent + '%';
        resultRow[0].classList.add('is-selected');

        resultSize[1].textContent = state.recommendation.secondary.label;
        resultPercent[1].textContent = state.recommendation.secondary.percent + '%';
        resultBar[1].style.width = state.recommendation.secondary.percent + '%';
        resultRow[1].classList.remove('is-selected');

        summary.textContent = textSummaryTemplate
            .replace('__SIZE__', state.recommendation.primary.label)
            .replace('__PERCENT__', String(state.recommendation.primary.percent));
        applyButton.textContent = textCtaTemplate.replace('__SIZE__', state.recommendation.primary.label);

        if (state.recommendation.primary.input) {
            applyRecommendedInput(state.recommendation.primary.input);
            const recommendedLabel = String(state.recommendation.primary.label || '').trim();
            if (recommendedLabel !== '') {
                state.savedSizeSignature = currentSizeSignature;
                state.savedSizeLabel = recommendedLabel.toUpperCase();
                updateFitFinderOpenButtons(recommendedLabel);
                persistFitPreference(recommendedLabel);
            }
        }
    };

    const renderStep = function () {
        steps.forEach(function (step, index) {
            step.classList.toggle('hidden', index !== state.step);
        });

        timelineItems.forEach(function (item, index) {
            item.classList.toggle('is-current', index === state.step);
            item.classList.toggle('is-done', index < state.step);
        });

        const totalInputSteps = 5;
        progress.textContent = state.step < totalInputSteps
            ? textStepTemplate
                .replace('__CURRENT__', String(state.step + 1))
                .replace('__TOTAL__', String(totalInputSteps))
            : textRecommendationReady;

        prevButton.classList.toggle('invisible', state.step === 0);
        nextButton.classList.toggle('hidden', state.step >= steps.length - 1);
        applyButton.classList.toggle('hidden', state.step < steps.length - 1);

        if (state.step === steps.length - 1) {
            renderResults();
        }
    };

    const setHelpToggleState = function (isOpen) {
        if (helpToggleButton) {
            helpToggleButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        }
        if (helpIconClosed) {
            helpIconClosed.classList.toggle('hidden', isOpen);
        }
        if (helpIconOpen) {
            helpIconOpen.classList.toggle('hidden', !isOpen);
        }
    };

    const openModal = function () {
        state.step = 0;
        clearStepErrors();
        renderStep();
        if (helpPanel) {
            helpPanel.classList.add('hidden');
        }
        setHelpToggleState(false);
        fitModal.classList.remove('hidden');
        fitModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('overflow-hidden');
    };

    const closeModal = function () {
        schedulePersist();
        if (helpPanel) {
            helpPanel.classList.add('hidden');
        }
        setHelpToggleState(false);
        fitModal.classList.add('hidden');
        fitModal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('overflow-hidden');
    };

    if (helpToggleButton && helpPanel) {
        helpToggleButton.addEventListener('click', function () {
            const shouldShow = helpPanel.classList.contains('hidden');
            helpPanel.classList.toggle('hidden', !shouldShow);
            setHelpToggleState(shouldShow);
        });
    }

    openButtons.forEach(function (button) {
        button.addEventListener('click', openModal);
    });

    closeButtons.forEach(function (button) {
        button.addEventListener('click', closeModal);
    });

    fitModal.addEventListener('click', function (event) {
        if (event.target === fitModal) {
            closeModal();
        }
    });

    nextButton.addEventListener('click', function () {
        if (!validateCurrentStep()) {
            return;
        }
        if (state.step < steps.length - 1) {
            state.step += 1;
            renderStep();
            schedulePersist();
        }
    });

    prevButton.addEventListener('click', function () {
        if (state.step > 0) {
            clearStepErrors();
            state.step -= 1;
            renderStep();
            schedulePersist();
        }
    });

    fitModal.querySelectorAll('[data-fit-fit]').forEach(function (button) {
        button.addEventListener('click', function () {
            state.fit = button.dataset.fitFit || 'average';
            setPressed('data-fit-fit', state.fit);
            schedulePersist();
        });
    });

    fitModal.querySelectorAll('[data-fit-chest]').forEach(function (button) {
        button.addEventListener('click', function () {
            state.chest = button.dataset.fitChest || 'average';
            setPressed('data-fit-chest', state.chest);
            schedulePersist();
        });
    });

    fitModal.querySelectorAll('[data-fit-belly]').forEach(function (button) {
        button.addEventListener('click', function () {
            state.belly = button.dataset.fitBelly || 'average';
            setPressed('data-fit-belly', state.belly);
            schedulePersist();
        });
    });

    applyButton.addEventListener('click', function () {
        if (!state.recommendation || !state.recommendation.primary.input) {
            return;
        }

        applyRecommendedInput(state.recommendation.primary.input);
        state.savedSizeSignature = currentSizeSignature;
        state.savedSizeLabel = String(state.recommendation.primary.label || '').trim().toUpperCase();
        persistFitPreference(state.recommendation.primary.label);
        closeModal();

        const selectedOptionSelect = form.querySelector('select[name="product_option_value_id"]');
        const hasSelectedForSubmit = selectedOptionSelect
            ? (!selectedOptionSelect.disabled && String(selectedOptionSelect.value || '').trim() !== '')
            : !!form.querySelector('input[name="product_option_value_id"]:checked');
        if (hasSelectedForSubmit) {
            const submitButton = form.querySelector('button[type="submit"]:not([form])');
            if (submitButton) {
                form.requestSubmit(submitButton);
            }
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !fitModal.classList.contains('hidden')) {
            closeModal();
        }
    });

    setPressed('data-fit-fit', state.fit);
    setPressed('data-fit-chest', state.chest);
    setPressed('data-fit-belly', state.belly);

    if (!inputHeight.value) {
        inputHeight.value = '170';
    }
    if (!inputWeight.value) {
        inputWeight.value = '70';
    }
    if (!inputAge.value) {
        inputAge.value = '30';
    }

    if (fitModal.dataset.fitInitialHeight) {
        inputHeight.value = String(fitModal.dataset.fitInitialHeight);
    }
    if (fitModal.dataset.fitInitialWeight) {
        inputWeight.value = String(fitModal.dataset.fitInitialWeight);
    }
    if (fitModal.dataset.fitInitialAge) {
        inputAge.value = String(fitModal.dataset.fitInitialAge);
    }

    syncRangeValue(inputHeight, inputHeightValue);
    syncRangeValue(inputWeight, inputWeightValue);
    syncRangeValue(inputAge, inputAgeValue);

    inputHeight.addEventListener('input', function () {
        syncRangeValue(inputHeight, inputHeightValue);
        schedulePersist();
    });
    inputWeight.addEventListener('input', function () {
        syncRangeValue(inputWeight, inputWeightValue);
        schedulePersist();
    });
    inputAge.addEventListener('input', function () {
        syncRangeValue(inputAge, inputAgeValue);
        schedulePersist();
    });

    if (initialSizeLabel !== '') {
        const signatureMatches = initialSizeSignature !== '' && initialSizeSignature === currentSizeSignature;
        const savedSizeInput = signatureMatches ? sizeOptions.find(function (sizeOption) {
            return String(sizeOption.label || '').trim().toUpperCase() === initialSizeLabel;
        }) : null;

        if (savedSizeInput) {
            applyRecommendedInput(savedSizeInput.input);
            state.savedSizeSignature = currentSizeSignature;
            state.savedSizeLabel = initialSizeLabel;
            updateFitFinderOpenButtons(initialSizeLabel);
        } else {
            state.savedSizeLabel = '';
            updateFitFinderOpenButtons('');
        }
    }

});
