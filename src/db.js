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

async function init() {
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
