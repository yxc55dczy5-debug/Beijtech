const sqlite3 = require('sqlite3').verbose();
const bcrypt = require('bcryptjs');
const path = require('path');
const fs = require('fs');

const dataDir = path.join(__dirname, '..', 'data');
fs.mkdirSync(dataDir, { recursive: true });

const db = new sqlite3.Database(path.join(dataDir, 'beijtech.db'));

function run(sql, params = []) {
  return new Promise((resolve, reject) => {
    db.run(sql, params, function (err) {
      if (err) reject(err);
      else resolve({ lastID: this.lastID, changes: this.changes });
    });
  });
}

function get(sql, params = []) {
  return new Promise((resolve, reject) => {
    db.get(sql, params, (err, row) => {
      if (err) reject(err);
      else resolve(row);
    });
  });
}

function all(sql, params = []) {
  return new Promise((resolve, reject) => {
    db.all(sql, params, (err, rows) => {
      if (err) reject(err);
      else resolve(rows);
    });
  });
}

async function init() {
  await run('PRAGMA journal_mode = WAL');
  await run('PRAGMA foreign_keys = ON');

  await run(`CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'admin',
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    last_login TEXT
  )`);

  await run(`CREATE TABLE IF NOT EXISTS sessions (
    token TEXT PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  )`);

  await run(`CREATE TABLE IF NOT EXISTS products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    category TEXT NOT NULL,
    description TEXT,
    image_path TEXT,
    popular INTEGER NOT NULL DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
  )`);

  await run(`CREATE TABLE IF NOT EXISTS quote_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    naam TEXT NOT NULL,
    email TEXT NOT NULL,
    telefoon TEXT,
    bedrijf TEXT,
    locatie TEXT,
    date_from TEXT,
    date_to TEXT,
    levering_heen INTEGER DEFAULT 0,
    levering_retour INTEGER DEFAULT 0,
    opmerkingen TEXT,
    status TEXT NOT NULL DEFAULT 'nieuw',
    admin_notes TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
  )`);

  await run(`CREATE TABLE IF NOT EXISTS quote_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    quote_id INTEGER NOT NULL REFERENCES quote_requests(id) ON DELETE CASCADE,
    product_id INTEGER,
    product_name TEXT NOT NULL,
    quantity INTEGER NOT NULL DEFAULT 1
  )`);

  // Seed default admin if no users exist
  const userRow = await get('SELECT COUNT(*) as count FROM users');
  if (userRow.count === 0) {
    const passwordHash = bcrypt.hashSync('beijtech2024', 10);
    await run(
      `INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'admin')`,
      ['admin', 'info@beijtech.nl', passwordHash]
    );
    console.log('Default admin user created: admin / beijtech2024');
  }

  // Seed default products if none exist
  const productRow = await get('SELECT COUNT(*) as count FROM products');
  if (productRow.count === 0) {
    const defaultProducts = [
      { name: 'LD Systems MAUI 28 G2 Column PA', category: 'geluid', description: 'Column PA-systeem — compact, krachtig geluid tot ±150 personen. Inclusief subwoofer en kabels.', popular: 1, sort_order: 1 },
      { name: 'QSC K12.2 Actieve Speaker', category: 'geluid', description: '2000W actieve full-range speaker. Kristalhelder geluid, ideaal als hoofd- of monitorluidspreker.', popular: 1, sort_order: 2 },
      { name: 'LD Systems MAUI 44 G2 Sub', category: 'geluid', description: 'Krachtige subwoofer voor diepe bas. Geschikt voor feesten en evenementen tot ±300 personen.', popular: 1, sort_order: 3 },
      { name: 'Showtec Compact Par 7 Tri Uplight', category: 'licht', description: 'Draadloze RGB uplight, ideaal voor sfeerverlichting langs wanden. Per stuk of per set.', popular: 0, sort_order: 4 },
      { name: 'Chauvet DJ Intimidator Spot 375Z', category: 'licht', description: 'Moving head spot met zoom — voor dynamische lichtshows op elk podium of dansvloer.', popular: 1, sort_order: 5 },
      { name: 'Showtec Performer 2500 Fresnel', category: 'licht', description: 'Krachtige theaterfresnel voor scèneverlichting, toneelspelen of productie-opnames.', popular: 0, sort_order: 6 },
      { name: 'Shure SM58 Dynamische Microfoon', category: 'microfoon', description: 'De industrie-standaard voor vocals. Robuust, betrouwbaar. Inclusief standaard en kabel.', popular: 1, sort_order: 7 },
      { name: 'LD Systems U508 HHD 2 Dubbel draadloos', category: 'microfoon', description: 'Professioneel dubbel draadloos systeem inclusief ontvanger. Ideaal voor sprekers en zangers.', popular: 1, sort_order: 8 },
      { name: 'Rookmachine 1500W', category: 'overig', description: 'Krachtige rookmachine voor sfeereffecten. Incl. rookvloeistof voor één avond.', popular: 0, sort_order: 9 },
      { name: 'Podiumtrap / Verhogingsplateau', category: 'overig', description: 'Modulaire podiumelementen, 2×1 m, instelbare hoogte 40–80 cm. Eenvoudig op te bouwen.', popular: 0, sort_order: 10 },
      { name: 'Statieven (microfoon / licht)', category: 'overig', description: 'Verstelbare statieven voor microfoon of lichtarmatuur. Per stuk of set.', popular: 0, sort_order: 11 },
      { name: 'Allen & Heath ZEDi-10FX Mixer', category: 'geluid', description: 'Compact 10-kanaals mengpaneel met ingebouwde FX. Voor kleine tot middelgrote evenementen.', popular: 0, sort_order: 12 }
    ];

    for (const p of defaultProducts) {
      await run(
        `INSERT INTO products (name, category, description, popular, active, sort_order) VALUES (?, ?, ?, ?, 1, ?)`,
        [p.name, p.category, p.description, p.popular, p.sort_order]
      );
    }
    console.log(`Seeded ${defaultProducts.length} default products.`);
  }
}

module.exports = { run, get, all, init };
