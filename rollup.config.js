// rollup.config.js
export default {
  input: './amd/src/alma_ai_tutor.js',
  output: {
    file: './amd/build/alma_ai_tutor.js',
    format: 'amd',
    sourcemap: false
  }
};
