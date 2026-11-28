# Deployment notes

Each organization that runs SSVS keeps its own setup notes outside this repository, somewhere its admins can reach (a private repository, a wiki or a shared drive). This folder holds only this guide. Anything else you put here is ignored by git, so a local copy of your notes won't be committed by accident.

Good notes cover:

- **People:** who owns the install, who maintains it, and who the coordinators are (by committee or role, not volunteers' names).
- **Areas:** the areas you schedule and which phase each starts in.
- **Schedule shape:** the days and blocks you staff, how many people each block needs, closures, and how many shadow shifts a new volunteer does.
- **Integrations:** your membership system, chat, email provider and calendar, and how your membership categories map to SSVS (for example, the `--lead-tag` and `--cleared-tag` values for `ssvs:import-people`).
- **Hosting:** the server, the database, and where backups go. How to install SSVS is in [docs/install.md](../install.md); your notes only need what's particular to your server, such as its name, your host's snapshot backups and where the backup key is kept.
- **Launch plan:** the steps from a test copy to inviting everyone.

Never write passwords, API keys or the `.env` file into the notes.
