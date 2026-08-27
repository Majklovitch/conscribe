CookieConsent.run({
    guiOptions: {
        consentModal: {
            layout: "box inline",
            position: "bottom right",
            equalWeightButtons: true,
            flipButtons: false
        },
        preferencesModal: {
            layout: "box",
            position: "right",
            equalWeightButtons: true,
            flipButtons: true
        }
    },
    categories: {
        necessary: {
            readOnly: true
        },
        analytics: {}
    },
    language: {
        default: "en",
        translations: {
            en: {
                consentModal: {
                    title: "Privacy settings",
                    description: "We use cookies to give you the best possible experience. Some are essential, others help us improve the site. ",
                    closeIconLabel: "",
                    acceptAllBtn: "Accept all",
                    acceptNecessaryBtn: "Reject all",
                    showPreferencesBtn: "Preferences",
                    footer: ""
                },
                preferencesModal: {
                    title: "Privacy settings",
                    closeIconLabel: "Close dialog",
                    acceptAllBtn: "Accept all",
                    acceptNecessaryBtn: "Reject all",
                    savePreferencesBtn: "Save preferences",
                    serviceCounterLabel: "Service|Services",
                    sections: [
                        {
                            title: "",
                            description: "Choose which cookies we may use. Technical and security cookies (e.g. spam protection) are required for the site to work correctly."
                        },
                        {
                            title: "Essential & security<span class=\"pm__badge\">Always on</span>",
                            description: "Required for the site to function and to protect forms from spam (reCAPTCHA).",
                            linkedCategory: "necessary"
                        },
                        {
                            title: "Analytics",
                            description: "They help us improve the site by measuring traffic.",
                            linkedCategory: "analytics"
                        }
                    ]
                }
            }
        }
    }
});

// --- Mobile navigation ------------------------------------------------------
// The header is collapsed on small screens; the button only toggles a class,
// so the state stays readable for screen readers too (aria-expanded).
document.addEventListener('DOMContentLoaded', function () {
    var toggle = document.querySelector('[data-nav-toggle]');
    var nav = document.getElementById('site-nav');

    if (toggle && nav) {
        toggle.addEventListener('click', function () {
            var open = nav.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }

    // The "Back" button on the error page. When the visitor arrived directly
    // (empty history), send them to the home page instead.
    document.querySelectorAll('[data-history-back]').forEach(function (button) {
        button.addEventListener('click', function () {
            if (window.history.length > 1) {
                window.history.back();
            } else {
                window.location.href = '/';
            }
        });
    });
});
