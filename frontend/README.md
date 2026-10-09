## NSSF frontend

This directory contains the standalone React application. Install with `npm ci`, then run `npm run dev` for local development. Copy `.env.example` to `.env` only if it does not already exist; the local API defaults to `http://localhost:8000/api`.

Production API configuration uses `VITE_API_BASE_URL`, embedded by Vite during `npm run build`. Set it to the approved backend origin plus `/api`, or create `.env.production` from `.env.production.example`. The legacy `VITE_API_URL` name remains supported. Never put secrets in VITE variables.

Build `dist` on the laptop and deploy its contents as static files; no Node server is required in production. Use the root repository's [deployment guide](../docs/DEPLOYMENT_GUIDE.md) for Windows CMD syntax, CORS, Nginx, SPA fallback and backend port 8001. Read [audit results](../docs/AUDIT_RESULTS.md) for the current lint warnings and build warnings.

# React + Vite

This template provides a minimal setup to get React working in Vite with HMR and some ESLint rules.

Currently, two official plugins are available:

- [@vitejs/plugin-react](https://github.com/vitejs/vite-plugin-react/blob/main/packages/plugin-react) uses [Oxc](https://oxc.rs)
- [@vitejs/plugin-react-swc](https://github.com/vitejs/vite-plugin-react/blob/main/packages/plugin-react-swc) uses [SWC](https://swc.rs/)

## React Compiler

The React Compiler is not enabled on this template because of its impact on dev & build performances. To add it, see [this documentation](https://react.dev/learn/react-compiler/installation).

## Expanding the ESLint configuration

If you are developing a production application, we recommend using TypeScript with type-aware lint rules enabled. Check out the [TS template](https://github.com/vitejs/vite/tree/main/packages/create-vite/template-react-ts) for information on how to integrate TypeScript and [`typescript-eslint`](https://typescript-eslint.io) in your project.
