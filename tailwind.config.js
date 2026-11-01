import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import typography from '@tailwindcss/typography';
const daisyui = require('daisyui')

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: ['selector', '[data-theme="dark"]'],
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './vendor/laravel/jetstream/**/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Manrope Variable', ...defaultTheme.fontFamily.sans],
            },
            colors: Object.fromEntries([
                'base-100', 'base-200', 'base-300', 'base-content',
                'primary', 'primary-content', 'secondary', 'secondary-content',
                'accent', 'accent-content', 'neutral', 'neutral-content',
                'info', 'info-content', 'success', 'success-content',
                'warning', 'warning-content', 'error', 'error-content',
            ].map(name => [name, `rgb(var(--rgb-${name}) / <alpha-value>)`])),
            padding: {
                'safe-b': 'env(safe-area-inset-bottom)',
                'safe-t': 'env(safe-area-inset-top)',
            }
        },
    },

    plugins: [
        daisyui,
        forms({ strategy: 'class' }),
        typography
    ],
    daisyui: {
        themes: [
            'dark',
        ],
    },
};
