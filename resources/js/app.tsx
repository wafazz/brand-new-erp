import { createInertiaApp } from '@inertiajs/react'
import { createRoot } from 'react-dom/client'
import type { ComponentType } from 'react'

const appName = import.meta.env.VITE_APP_NAME ?? 'SME ERP'

void createInertiaApp({
    title: (title) => (title ? `${title} — ${appName}` : appName),
    resolve: (name) => {
        const pages = import.meta.glob<{ default: ComponentType }>('./Pages/**/*.tsx', { eager: true })
        const page = pages[`./Pages/${name}.tsx`]

        if (!page) {
            throw new Error(`Inertia page [${name}] was not found at ./Pages/${name}.tsx`)
        }

        return page
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />)
    },
    progress: { color: '#1f6feb' },
})
