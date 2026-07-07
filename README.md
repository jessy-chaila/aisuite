# AI Suite (GLPI 11) 🤖✨

**One plugin, four AI-powered assistants for your helpdesk.**

AI Suite is a GLPI plugin that brings AI assistance to every stage of ticket handling: level 2 technical analysis, automatic categorization, a level 1 chatbot, and automatic first response — all four modules sharing a single AI provider configuration.

**Compatibility:** Developed for **GLPI 11+** (Verified on v11.0.8).

## 📦 What's inside — 4 modules, 1 plugin

### 🔍 AI Smart Check — Level 2 ticket analysis
Adds an "AI Smart Check" tab directly on the ticket view. The AI reads the title and description and acts as a level 2 assistant: it suggests a step-by-step resolution checklist, looks up relevant Knowledge Base articles, and lets the technician export its analysis as a private note in one click.

### 🗂️ AI Smart Sorter — automatic categorization, type & asset linking
Runs automatically when a ticket is created. It acts as an intelligent dispatcher: it assigns the most relevant ITIL category, sets the appropriate ticket type (Incident or Request), and detects which hardware asset (computer, monitor, printer…) the user is referring to, then either suggests the changes or applies them automatically ("Auto-Pilot" mode) once a configurable confidence threshold is reached. Ticket types are read directly from the running GLPI instance, so their labels always match its language and configuration.

### 🤖 AI Chatbot — Level 1 support agent
A floating chat bubble available across GLPI. It acts as a level 1 support agent, answering simple non-technical questions, and automatically proposes opening a ticket (with full conversation history) when the request needs a human.

### 🧑‍💻 AI Level 1 Assistant — automatic first response, right inside the ticket
Runs automatically when a ticket is created and replies directly in the ticket itself. If the request can be solved with steps that require no admin rights and no technical skill, it posts a ready-to-follow solution for the requester. If not, it asks a short list of clarifying questions and reassigns the ticket to the technician group configured for this module. End users on the Helpdesk (self-service) interface can also click a dedicated button on the ticket to skip the AI entirely and request a human technician straight away.

## 🚀 Key features

* **Single shared AI provider** — configure your AI connection once in the *AI Providers* tab and all four modules use it. Three provider families are supported everywhere: **OpenAI-compatible** (OpenAI, Azure OpenAI, xAI/Grok, Mistral), **Anthropic (Claude)** and **Google (Gemini)**. Each family keeps its own URL/key/model even when you switch the active one.
* **Per-module enable/disable** — turn any of the four modules on or off independently from its own configuration tab (e.g. keep only the chatbot active). Disabling a module fully unregisters its GLPI tab/hooks/assets, not just hides it.
* **User-editable cost estimation** — set your own input/output price per 1M tokens for each provider family directly in the config screen; the cost/token estimates shown in Smart Check and Smart Sorter update automatically, no code changes needed.
* **Cost & token tracking** — every AI call displays its estimated cost and token usage (Smart Check tab, Smart Sorter popup and history dashboard).
* **Audit trail** — Smart Sorter logs every AI decision (category, type, hardware, confidence, cost) and creates a private task in the ticket for full traceability.
* **One-click connection test** — validate any provider's API key/URL/model straight from the config screen before saving.

## 🛠️ Configuration

Everything is managed from **Setup > Plugins > AI Suite**, a single screen with 5 tabs:

* **AI Providers** — pick the active provider family and fill in its URL, API key, model/deployment and cost-estimation prices. A "Test connection" button lets you validate credentials instantly.
* **AI Smart Check** — enable/disable the module, customize its system prompt, and toggle Knowledge Base search.
* **AI Smart Sorter** — enable/disable the module, configure Auto-Pilot mode and its confidence threshold, toggle hardware linking, customize the system prompt, and browse the analysis history. Category and ticket type suggestions are always included; hardware linking is optional.
* **AI Level 1 Assistant** — enable/disable the module, pick the technician group tickets get reassigned to when the AI can't resolve them, and customize the system prompt.
* **AI Chatbot** — enable/disable the module, set the support phone number, customize the system prompt, and personalize the bubble's icon and color.

## 📋 Prerequisites

* **GLPI 11+** (functionality verified on v11.0.8).
* **PHP 8.2+**.
* A valid API key for at least one supported provider family (OpenAI/Azure/xAI/Mistral, Anthropic, or Google Gemini).

## 💻 Installation

Clone this repository into your GLPI `plugins/` directory, then hand ownership of the files to the web server user so GLPI can read (and, for the icon upload feature, write to) them:

    cd /var/www/html/glpi/plugins
    git clone https://github.com/jessy-chaila/aisuite.git
    chown -R www-data:www-data aisuite
    find aisuite -type d -exec chmod 755 {} \;
    find aisuite -type f -exec chmod 644 {} \;
    chmod -R 775 aisuite/public/img

> Replace `www-data` with whichever user your web server / PHP-FPM pool actually runs as (e.g. `apache`, `nginx`, or `glpi` on some distributions).

1. Log in to GLPI.
2. Go to **Setup > Plugins**.
3. Click **Install** and then **Enable** for "AI Suite".
4. Go to **Setup > Plugins > AI Suite** to configure your provider and each module.

## 🛡️ Security & Ethics

The suite is designed to assist, not replace, and to keep humans in control:

* **Advisor role** — Smart Check only suggests actions; the technician validates and checks them off manually.
* **Optional automation** — Smart Sorter's Auto-Pilot is opt-in; it can run in suggestion-only mode to keep a human in the loop at all times.
* **Data privacy** — only ticket titles/descriptions, relevant KB article titles, and the user's asset names are sent to the AI provider; no unrelated personal data is scraped.
* **Traceability** — every AI action (analysis, categorization, hardware link, chat-originated ticket, Level 1 resolution/escalation) is logged or recorded as a followup/private task.
* **User control** — end users can always opt out of the AI Level 1 Assistant on a given ticket and request a human technician directly, from the Helpdesk (self-service) interface.
* **Safe execution** — no direct system commands are ever executed by the AI, and hardware linking is restricted to a whitelist of known GLPI item types.
* **Access control** — every AJAX endpoint enforces GLPI session/rights checks and CSRF token validation, and every state-changing action (ticket creation from chat, Smart Sorter apply/dismiss, Level 1 opt-out) fetches a fresh CSRF token immediately before its request, compatible with GLPI 11's own Kernel-level CSRF enforcement.

## 🗄️ Database

Each module stores its own data in dedicated tables: `glpi_plugin_aismartcheck_analyses` (Smart Check), `glpi_plugin_aismartsorter_logs` (Smart Sorter), `glpi_plugin_aisuite_level1_logs` (Level 1 Assistant).

## 📄 License

GPLv2+ — see [LICENSE](LICENSE).
