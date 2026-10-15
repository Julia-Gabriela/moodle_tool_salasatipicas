// This file is part of Moodle - http://moodle.org/

/**
 * Copy the ready course URL without navigating away from the history.
 * @module tool_salasatipicas/copy_course_link
 */

/** Compatibility path; the temporary field is never displayed in the grid. */
const legacyCopy = (text) => {
    const previousFocus = document.activeElement;
    const selection = window.getSelection();
    const ranges = [];
    if (selection) {
        for (let index = 0; index < selection.rangeCount; index++) {
            ranges.push(selection.getRangeAt(index).cloneRange());
        }
    }
    const field = document.createElement('textarea');
    field.value = text;
    field.readOnly = true;
    field.style.position = 'fixed';
    field.style.left = '-9999px';
    field.style.top = '0';
    document.body.appendChild(field);
    try {
        field.focus({preventScroll: true});
        field.select();
        return document.execCommand('copy') === true;
    } catch (error) {
        return false;
    } finally {
        field.remove();
        if (selection) {
            selection.removeAllRanges();
            ranges.forEach((range) => selection.addRange(range));
        }
        if (previousFocus && previousFocus.focus) {
            previousFocus.focus({preventScroll: true});
        }
    }
};

const copyText = async(text) => {
    if (window.isSecureContext && navigator.clipboard && navigator.clipboard.writeText) {
        try {
            await navigator.clipboard.writeText(text);
            return true;
        } catch (error) {
            // Browser settings can deny Clipboard API access; try the user-triggered fallback.
        }
    }
    return legacyCopy(text);
};

export const init = (labels) => {
    document.querySelectorAll('.salasdep-copy-link').forEach((button) => {
        if (button.dataset.copyBound === '1') {
            return;
        }
        button.dataset.copyBound = '1';
        button.disabled = false;
        let busy = false;
        let resetTimer;
        button.addEventListener('click', async(event) => {
            event.preventDefault();
            if (busy) {
                return;
            }
            busy = true;
            button.setAttribute('aria-busy', 'true');
            const feedback = button.parentElement.querySelector('.salasdep-copy-feedback');
            window.clearTimeout(resetTimer);
            if (feedback) {
                feedback.textContent = '';
            }
            let copied = false;
            try {
                const url = button.dataset.courseUrl;
                if (url) {
                    copied = await copyText(url);
                }
            } catch (error) {
                copied = false;
            } finally {
                busy = false;
                button.removeAttribute('aria-busy');
            }
            if (feedback) {
                feedback.textContent = copied ? labels.copied : labels.failed;
                if (copied) {
                    resetTimer = window.setTimeout(() => {
                        feedback.textContent = '';
                    }, 3000);
                }
            }
        });
    });
};
