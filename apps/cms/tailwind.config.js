/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './templates/**/*.html.twig',
    '../packages/**/templates/**/*.html.twig',
    './node_modules/tw-elements/js/**/*.js',
  ],
  theme: {
    extend: {
      fontFamily: {
        sans: ['system-ui', 'Segoe UI', 'Roboto', 'sans-serif'],
      },
    },
  },
  plugins: [require('tw-elements/plugin.cjs')],
};
