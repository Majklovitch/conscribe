// Inicializace Cookie Consent v3
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
                    title: "Nastavení soukromí",
                    description: "Používáme cookies, abychom vám zajistili co nejlepší zážitek. Některé jsou nezbytné, jiné nám pomáhají zlepšovat náš web. ",
                    closeIconLabel: "",
                    acceptAllBtn: "Příjmout vše",
                    acceptNecessaryBtn: "Odmítnout vše",
                    showPreferencesBtn: "Nastavení",
                    footer: ""
                },
                preferencesModal: {
                    title: "Nastavení soukromí",
                    closeIconLabel: "Zavřít okno",
                    acceptAllBtn: "Příjmout vše",
                    acceptNecessaryBtn: "Odmítnout vše",
                    savePreferencesBtn: "Uložit nastavení",
                    serviceCounterLabel: "Service|Services",
                    sections: [
                        {
                            title: "",
                            description: "Vyberte, které soubory cookies můžeme používat. Technické a bezpečnostní cookies (např. ochrana proti spamu) jsou nezbytné pro správné fungování webu."
                        },
                        {
                            title: "Nezbytné & Bezpečnostní<span class=\"pm__badge\">Vždy zapnuté</span>",
                            description: "Nutné pro funkčnost webu a ochranu formuláře před spamem (reCAPTCHA).",
                            linkedCategory: "necessary"
                        },
                        {
                            title: "Analytické",
                            description: "Pomáhají nám vylepšovat web měřením návštěvnosti.",
                            linkedCategory: "analytics"
                        }
                    ]
                }
            }
        }
    }
});