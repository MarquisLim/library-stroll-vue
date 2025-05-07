import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import typography from '@tailwindcss/typography';
const daisyui = require('daisyui')

/** @type {import('tailwindcss').Config} */
export default {
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
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            padding: {
                'safe-b': 'env(safe-area-inset-bottom)',
                'safe-t': 'env(safe-area-inset-top)',
            }
        },
    },

    plugins: [forms, typography, daisyui],
    daisyui: {
        themes: ['light', 'dark'],
    },
};
