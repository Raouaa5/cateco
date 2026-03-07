module.exports = {
  content: [
    './templates/**/*.twig',
    './themes/**/*.twig',
    './assets/**/*.js'
  ],
  theme: {
    extend: {
      colors: {
        'cateco-orange': '#F97316',
        'cateco-dark': '#1F2937',
        'cateco-gold': '#F59E0B'
      }
    }
  },
  plugins: [],
}
