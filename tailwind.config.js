import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    // Every template this application renders and the markup its scripts build:
    // nothing else. The pagination and error views are the application's own
    // copies under resources/views. Not storage/framework/views: that cache
    // holds whatever was rendered on the machine doing the build, the
    // framework's own error and mail pages included, so two machines built two
    // different stylesheets. tests/js/tailwind-content.check.mjs holds this.
    content: [
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
    ],

    theme: {
        borderRadius: {
            'none': '0',
            'sm': '6px',
            'DEFAULT': '10px',
            'md': '12px',
            'lg': '14px',
            'xl': '16px',
            '2xl': '20px',
            '3xl': '24px',
            'full': '9999px',
        },
        extend: {
            fontFamily: {
                sans: ['system-ui', '"Noto Sans"', '"Segoe UI"', 'Roboto', '"Noto Sans Devanagari"', 'sans-serif'],
            },
            colors: {
                brand: {
                    50: '#f1f3f8', 100: '#e3e7f0', 200: '#c6cee0', 300: '#9aa8c4',
                    400: '#5a6e95', 500: '#2c3f66', 600: '#1a2a4a', 700: '#101c36',
                },
                // No yellow in Dhiran: a stray amber/yellow/orange utility paints in the ink ramp, never in a yellow.
                amber: {
                    50: '#f1f3f8', 100: '#e3e7f0', 200: '#c6cee0', 300: '#9aa8c4',
                    400: '#5a6e95', 500: '#2c3f66', 600: '#1a2a4a', 700: '#101c36',
                },
                yellow: {
                    50: '#f1f3f8', 100: '#e3e7f0', 200: '#c6cee0', 300: '#9aa8c4',
                    400: '#5a6e95', 500: '#2c3f66', 600: '#1a2a4a', 700: '#101c36',
                },
                orange: {
                    50: '#f1f3f8', 100: '#e3e7f0', 200: '#c6cee0', 300: '#9aa8c4',
                    400: '#5a6e95', 500: '#2c3f66', 600: '#1a2a4a', 700: '#101c36',
                },
                surface: {
                    DEFAULT: '#ffffff',
                    soft: '#f8f9fc',
                    muted: '#f1f3f9',
                    hover: '#eef1f8',
                },
                ink: {
                    DEFAULT: '#101828',
                    muted: '#475467',
                    subtle: '#667085',
                },
            },
            boxShadow: {
                'card': '0 1px 3px rgba(16, 24, 40, 0.06), 0 1px 2px rgba(16, 24, 40, 0.04)',
                'card-hover': '0 4px 12px rgba(16, 24, 40, 0.08), 0 1px 3px rgba(16, 24, 40, 0.06)',
                'elevated': '0 8px 24px rgba(16, 24, 40, 0.08)',
                'modal': '0 20px 48px rgba(16, 24, 40, 0.18)',
            },
        },
    },

    plugins: [forms],
};
