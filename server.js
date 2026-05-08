const express = require('express');
const cors = require('cors');
const path = require('path');
const fs = require('fs');

fs.mkdirSync(path.join(__dirname, 'data'), { recursive: true });
fs.mkdirSync(path.join(__dirname, 'uploads', 'products'), { recursive: true });

const { init: initDb } = require('./src/db');
const authRoutes = require('./src/routes/auth');
const productRoutes = require('./src/routes/products');
const quoteRoutes = require('./src/routes/quotes');

const app = express();
const PORT = process.env.PORT || 3000;

app.use(cors({ origin: '*' }));
app.use(express.json());
app.use(express.urlencoded({ extended: true }));

app.use('/uploads', express.static(path.join(__dirname, 'uploads')));
app.use(express.static(path.join(__dirname)));

app.use('/api/auth', authRoutes);
app.use('/api/products', productRoutes);
app.use('/api/quotes', quoteRoutes);

initDb()
  .then(() => {
    app.listen(PORT, () => {
      console.log(`BeijTech server running on port ${PORT}`);
    });
  })
  .catch((err) => {
    console.error('Database initialization failed:', err);
    process.exit(1);
  });

module.exports = app;
