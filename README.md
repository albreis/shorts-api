# 🎬 Shorts API

[![WordPress Plugin](https://img.shields.io/badge/WordPress-Plugin-blue.svg)](https://wordpress.org/plugins/shorts-api-optimized/)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Version](https://img.shields.io/badge/Version-2.1.0-green.svg)]()

**Shorts API** is a professional WordPress plugin designed to power high-performance, TikTok-style video portals. It bridges your WordPress content with a modern Node.js/Express backend to deliver video feeds with lightning speed.

---

## 🚀 Key Features

- **High-Performance Scaling**: Bypasses heavy `WP_Query` by synchronizing specific video metadata to an external Express API.
- **WP-CLI Optimization**: Bulk sync thousands of posts using the `wp shorts sync` command with built-in batching (`--amount=X`).
- **Real-time Synchronization**: Automatically updates your video feed whenever a post is published, updated, or deleted.
- **Media-Ready Settings**: Integrated WordPress Media Uploader for Logos and Favicons.
- **Multi-site Support**: Dynamic domain verification and Traefik-ready configuration sync.
- **GA4 Integration**: Native support for Google Analytics tracking IDs for your shorts frontend.

---

## 🛠 Technical Architecture

The plugin acts as a **Data Provider** for a decoupled frontend (React/Next.js).

1. **WordPress**: Content management and video attachment handling.
2. **Shorts API (Plugin)**: Extracts metadata and pushes to the backend.
3. **Express API**: Handles indexing, ranking, and high-concurrency requests.
4. **Redis**: Buffers actions (likes, views) and provides rate limiting (3 actions/min).

---

## 📦 Installation

1. Clone or download this repository into your `/wp-content/plugins/` folder.
   ```bash
   git clone https://github.com/ersolucoesweb/shorts-api.git
   ```
2. Activate the plugin in the WordPress Admin.
3. Go to **Shorts API** menu to configure your branding (Logo, Favicon, Color).
4. (Optional) Run your first sync via CLI:
   ```bash
   wp shorts sync --amount=100
   ```

---

## 💻 Screenshots

| Desktop View | Mobile View |
| :--- | :--- |
| ![Desktop](assets/screenshot-1.png) | ![Mobile](assets/screenshot-2.png) |
| ![Desktop](assets/screenshot-3.png) | ![Mobile](assets/screenshot-4.png) |

*(More screenshots available in the `assets/` directory)*

---

## 📜 Commands (WP-CLI)

- `wp shorts sync`: Synchronizes published posts. 
  - Usage: `wp shorts sync --amount=100`
- `wp shorts sync-domain`: Register/Update domain configuration in the backend.
- `wp shorts sync-configs`: Pushes settings (colors, logo, order) to the API.

---

## 📄 License

This project is licensed under the **GPLv2 or later**.

---

Developed with ❤️ by [ER Soluções Web](https://albreis.com.br)
