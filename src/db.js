require('dotenv').config();
const mysql = require('mysql2/promise');
const bcrypt = require('bcryptjs');

const pool = mysql.createPool({
  host:     process.env.DB_HOST     || 'localhost',
  port:     parseInt(process.env.DB_PORT || '3306'),
  user:     process.env.DB_USER,
  password: process.env.DB_PASSWORD,
  database: process.env.DB_NAME,
  waitForConnections: true,
  connectionLimit: 10,
  charset: 'utf8mb4'
});

async function run(sql, params = []) {
  const [result] = await pool.execute(sql, params);
  return { lastID: result.insertId, changes: result.affectedRows };
}

async function get(sql, params = []) {
  const [rows] = await pool.execute(sql, params);
  return rows[0] || null;
}

async function all(sql, params = []) {
  const [rows] = await pool.execute(sql, params);
  return rows;
}

async function transaction(fn) {
  const conn = await pool.getConnection();
  await conn.beginTransaction();

  const tx = {
    run: async (sql, params = []) => {
      const [result] = await conn.execute(sql, params);
      return { lastID: result.insertId, changes: result.affectedRows };
    },
    get: async (sql, params = []) => {
      const [rows] = await conn.execute(sql, params);
      return rows[0] || null;
    },
    all: async (sql, params = []) => {
      const [rows] = await conn.execute(sql, params);
      return rows;
    }
  };

  try {
    const result = await fn(tx);
    await conn.commit();
    return result;
  } catch (e) {
    await conn.rollback();
    throw e;
  } finally {
    conn.release();
  }
}

async function ensureSchema() {
  await run(`CREATE TABLE IF NOT EXISTS users (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(50) NOT NULL DEFAULT 'admin',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login DATETIME
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`);

  await run(`CREATE TABLE IF NOT EXISTS sessions (
    token VARCHAR(64) NOT NULL PRIMARY KEY,
    user_id INT NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`);

  await run(`CREATE TABLE IF NOT EXISTS products (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    category VARCHAR(100) NOT NULL,
    description TEXT,
    image_path VARCHAR(500),
    popular TINYINT(1) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    stock_quantity INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`);

  await run(`CREATE TABLE IF NOT EXISTS quote_requests (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    naam VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    telefoon VARCHAR(50),
    bedrijf VARCHAR(255),
    locatie VARCHAR(255),
    date_from DATE,
    date_to DATE,
    levering_heen TINYINT(1) DEFAULT 0,
    levering_retour TINYINT(1) DEFAULT 0,
    opmerkingen TEXT,
    status VARCHAR(50) NOT NULL DEFAULT 'nieuw',
    admin_notes TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`);

  await run(`CREATE TABLE IF NOT EXISTS quote_items (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    quote_id INT NOT NULL,
    product_id INT,
    product_name VARCHAR(255) NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    FOREIGN KEY (quote_id) REFERENCES quote_requests(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`);

  await run(`CREATE TABLE IF NOT EXISTS projects (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    naam VARCHAR(255) NOT NULL,
    klant VARCHAR(255),
    locatie VARCHAR(255),
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'gepland',
    opmerkingen TEXT,
    quote_id INT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (quote_id) REFERENCES quote_requests(id) ON DELETE SET NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`);

  await run(`CREATE TABLE IF NOT EXISTS project_items (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    product_id INT,
    product_name VARCHAR(255) NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`);
}

async function ensureDefaultAdmin() {
  const password = 'beijtech2024';
  const admin = await get('SELECT id, password_hash FROM users WHERE username = ?', ['admin']);

  if (!admin) {
    const passwordHash = bcrypt.hashSync(password, 10);
    const emailInUse = await get('SELECT id FROM users WHERE email = ?', ['info@beijtech.nl']);
    await run(
      `INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'admin')`,
      ['admin', emailInUse ? 'admin@beijtech.nl' : 'info@beijtech.nl', passwordHash]
    );
    console.log('Default admin user created: admin / beijtech2024');
    return;
  }

  if (!bcrypt.compareSync(password, admin.password_hash)) {
    const passwordHash = bcrypt.hashSync(password, 10);
    await run(
      `UPDATE users SET password_hash = ?, role = 'admin' WHERE id = ?`,
      [passwordHash, admin.id]
    );
    console.log('Default admin user repaired: admin / beijtech2024');
  }
}

async function init() {
  await ensureSchema();

  // Auto-migrate old databases that already had products before stock tracking.
  const stockColumn = await get("SHOW COLUMNS FROM products LIKE 'stock_quantity'");
  if (!stockColumn) {
    await run('ALTER TABLE products ADD COLUMN stock_quantity INT NOT NULL DEFAULT 0 AFTER sort_order');
  }

  await ensureDefaultAdmin();

  // Seed default products if none exist
  const productRow = await get('SELECT COUNT(*) as count FROM products');
  if (productRow.count === 0) {
    const defaultProducts = [
      { name: 'LD Systems MAUI 28 G2 Column PA',      category: 'geluid',    description: 'Column PA-systeem — compact, krachtig geluid tot ±150 personen. Inclusief subwoofer en kabels.',             popular: 1, sort_order: 1  },
      { name: 'QSC K12.2 Actieve Speaker',             category: 'geluid',    description: '2000W actieve full-range speaker. Kristalhelder geluid, ideaal als hoofd- of monitorluidspreker.',           popular: 1, sort_order: 2  },
      { name: 'LD Systems MAUI 44 G2 Sub',             category: 'geluid',    description: 'Krachtige subwoofer voor diepe bas. Geschikt voor feesten en evenementen tot ±300 personen.',                 popular: 1, sort_order: 3  },
      { name: 'Showtec Compact Par 7 Tri Uplight',     category: 'licht',     description: 'Draadloze RGB uplight, ideaal voor sfeerverlichting langs wanden. Per stuk of per set.',                      popular: 0, sort_order: 4  },
      { name: 'Chauvet DJ Intimidator Spot 375Z',      category: 'licht',     description: 'Moving head spot met zoom — voor dynamische lichtshows op elk podium of dansvloer.',                          popular: 1, sort_order: 5  },
      { name: 'Showtec Performer 2500 Fresnel',        category: 'licht',     description: 'Krachtige theaterfresnel voor scèneverlichting, toneelspelen of productie-opnames.',                          popular: 0, sort_order: 6  },
      { name: 'Shure SM58 Dynamische Microfoon',       category: 'microfoon', description: 'De industrie-standaard voor vocals. Robuust, betrouwbaar. Inclusief standaard en kabel.',                     popular: 1, sort_order: 7  },
      { name: 'LD Systems U508 HHD 2 Dubbel draadloos',category: 'microfoon', description: 'Professioneel dubbel draadloos systeem inclusief ontvanger. Ideaal voor sprekers en zangers.',               popular: 1, sort_order: 8  },
      { name: 'Rookmachine 1500W',                     category: 'overig',    description: 'Krachtige rookmachine voor sfeereffecten. Incl. rookvloeistof voor één avond.',                               popular: 0, sort_order: 9  },
      { name: 'Podiumtrap / Verhogingsplateau',        category: 'overig',    description: 'Modulaire podiumelementen, 2×1 m, instelbare hoogte 40–80 cm. Eenvoudig op te bouwen.',                      popular: 0, sort_order: 10 },
      { name: 'Statieven (microfoon / licht)',         category: 'overig',    description: 'Verstelbare statieven voor microfoon of lichtarmatuur. Per stuk of set.',                                     popular: 0, sort_order: 11 },
      { name: 'Allen & Heath ZEDi-10FX Mixer',         category: 'geluid',    description: 'Compact 10-kanaals mengpaneel met ingebouwde FX. Voor kleine tot middelgrote evenementen.',                   popular: 0, sort_order: 12 }
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

module.exports = { run, get, all, transaction, init };
