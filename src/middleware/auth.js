const { get, run } = require('../db');

async function requireAuth(req, res, next) {
  const authHeader = req.headers['authorization'] || '';
  const token = authHeader.startsWith('Bearer ') ? authHeader.slice(7) : null;

  if (!token) {
    return res.status(401).json({ error: 'Niet ingelogd: geen token opgegeven.' });
  }

  try {
    const session = await get(`
      SELECT s.token, s.user_id, s.expires_at,
             u.id, u.username, u.email, u.role
      FROM sessions s
      JOIN users u ON u.id = s.user_id
      WHERE s.token = ?
    `, [token]);

    if (!session) {
      return res.status(401).json({ error: 'Ongeldige sessie.' });
    }

    if (new Date(session.expires_at) < new Date()) {
      await run('DELETE FROM sessions WHERE token = ?', [token]);
      return res.status(401).json({ error: 'Sessie verlopen. Log opnieuw in.' });
    }

    req.user = {
      id: session.user_id,
      username: session.username,
      email: session.email,
      role: session.role
    };
    req.token = token;

    next();
  } catch (e) {
    return res.status(500).json({ error: e.message });
  }
}

module.exports = requireAuth;
