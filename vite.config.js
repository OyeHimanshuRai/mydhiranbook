import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
export default defineConfig({ plugins: [laravel({input:['resources/css/app.css','resources/css/dhiran.css','resources/css/dhiran-documents.css','resources/css/dhiran-settings.css','resources/css/dhiran-forms.css','resources/css/dhiran-receipt-settings.css','resources/css/dhiran-detail.css','resources/css/site.css','resources/css/admin.css','resources/js/app.js'],refresh:false})] });
