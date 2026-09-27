import React from 'react'
import ReactDOM from 'react-dom/client'
import App from './App.jsx'
import './index.css'
import './lib/mobileShell.js'
import { loadBranding } from './lib/branding'

// Public branding (site name, colors, logo) is fetched at boot and applied to
// the tab title + CSS variables; it resolves only after render so the shell
// never blocks on it.
loadBranding();

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>,
)
