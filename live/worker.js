// Mortez live: the always-on world. One Durable Object ("the Chateau") holds everyone's WebSocket and passes
// positions, emotes, preset phrases and room chat (filtered, rate limited) between reapers in the same room. Uses the WebSocket Hibernation API so idle time is not billed.
import { DurableObject } from "cloudflare:workers";

const MAX = 120;                                   // reapers per Chateau shard; the client opens shard 2, 3… when one is full
const KINDS = ["reaper", "lady", "brett", "rachele"];
const EMOTES = ["🔥", "🖤", "💀", "🦇", "👏", "🤘"];
const PHRASES = ["Love this song", "Sum fortis", "Join the coven", "Where's the key?", "Found a relic!", "This way"];
const ROOMS = /^[a-z]{2,12}$/;
const SID = /^[a-z0-9]{8,24}$/;
const STALE = 45000;                               // a reaper we haven't heard from in this long has gone (phone locked, tab closed, network dropped)

function allowed(origin, env) {
  if (!origin) return false;
  try {
    const h = new URL(origin).hostname;
    if (h === "mortezmusic.com" || h.endsWith(".mortezmusic.com")) return true;
    if (h === "localhost" || h === "127.0.0.1") return true;
    if (h.endsWith(".netlify.app")) return true;
    return (env.EXTRA_ORIGINS || "").split(",").map(s => s.trim()).filter(Boolean).includes(h);
  } catch (e) { return false; }
}

export default {
  async fetch(req, env) {
    const u = new URL(req.url);
    if (u.pathname === "/health") return new Response("ok", { headers: { "access-control-allow-origin": "*" } });
    if (u.pathname !== "/world") return new Response("Mortez live", { status: 404 });
    if (req.headers.get("Upgrade") !== "websocket") return new Response("Expected a WebSocket", { status: 426 });
    if (!allowed(req.headers.get("Origin"), env)) return new Response("Not allowed", { status: 403 });
    const shard = "chateau-" + Math.min(9, Math.max(1, parseInt(u.searchParams.get("s") || "1", 10) || 1));
    return env.WORLD.get(env.WORLD.idFromName(shard)).fetch(req);
  }
};

const num = (v, lo, hi) => { v = +v; return Number.isFinite(v) ? Math.min(hi, Math.max(lo, Math.round(v * 100) / 100)) : 0; };
const clean = t => String(t || "").replace(/[\u0000-\u001f\u007f<>]/g, "").trim().slice(0, 18);
// room chat: short, no links, the worst words masked. Players can also mute each other on their side.
const BAD = /\b(f+u+c+k+\w*|sh[i1]t+\w*|c+u+n+t+\w*|b[i1]tch\w*|n[i1]gg\w*|f[a@]gg?\w*|retard\w*|wh[o0]re\w*|slut\w*|d[i1]ck(s|head\w*)?|puss(y|ies)|c[o0]ck(s|sucker\w*)?|kys|kill\s+your\s*self)\b/gi;
const say = t => String(t || "").replace(/[\u0000-\u001f\u007f<>]/g, "").replace(/\s+/g, " ").trim().slice(0, 120)
  .replace(/(https?:\/\/|www\.)\S+|\b\S+\.(com|net|org|io|gg|ly|co|xyz|app|me)\b\S*/gi, "[link]")
  .replace(BAD, w => w[0] + "*".repeat(Math.max(2, w.length - 1)));

export class World extends DurableObject {
  async fetch(req) {
    const pair = new WebSocketPair(), [client, server] = Object.values(pair);
    if (this.ctx.getWebSockets().length >= MAX) {
      server.accept(); server.send(JSON.stringify({ k: "full" })); server.close(1013, "full");
      return new Response(null, { status: 101, webSocket: client });
    }
    const id = "p" + crypto.randomUUID().replace(/-/g, "").slice(0, 10);
    this.ctx.acceptWebSocket(server);
    server.serializeAttachment({ id, name: "", kind: "", st: null, w: 0, n: 0, t: Date.now(), sid: "" });
    this.sweep();
    const cin = await this.ctx.storage.get("cin");
    server.send(JSON.stringify({ k: "you", id, now: Date.now(), cin: cin || null, here: this.ctx.getWebSockets().length }));
    for (const ws of this.ctx.getWebSockets()) {
      if (ws === server) continue; const a = ws.deserializeAttachment(); if (!a || !a.name) continue;
      server.send(JSON.stringify({ k: "hi", id: a.id, name: a.name, kind: a.kind }));
      if (a.st) server.send(JSON.stringify(Object.assign({ k: "st", id: a.id }, a.st)));
    }
    return new Response(null, { status: 101, webSocket: client });
  }

  send(m, filter, except) {
    const s = JSON.stringify(m);
    for (const ws of this.ctx.getWebSockets()) {
      if (ws === except) continue;
      if (filter) { const a = ws.deserializeAttachment(); if (!a || !filter(a)) continue; }
      try { ws.send(s); } catch (e) {}
    }
  }

  async webSocketMessage(ws, raw) {
    if (typeof raw !== "string" || raw.length > 1500) return;
    const a = ws.deserializeAttachment(); if (!a) return;
    const t = Date.now(); if (t - a.w > 1000) { a.w = t; a.n = 0; } if (++a.n > 30) return;   // no more than 30 messages a second
    if (t - a.t > 5000) { a.t = t; ws.serializeAttachment(a); this.sweep(); }   // note we heard from them; now and then clear out the silent ones
    let m; try { m = JSON.parse(raw); } catch (e) { return; }
    if (!m || typeof m.k !== "string") return;
    const sp = a.st ? a.st.sp : "", here = b => b.st && b.st.sp === sp;
    switch (m.k) {
      case "hi": {
        a.name = clean(m.name) || "Reaper"; a.kind = KINDS.includes(m.kind) ? m.kind : "reaper";
        // the same browser tab reconnecting (after a dropped network or a locked phone): close its old connection so it doesn't linger as a frozen twin
        if (typeof m.sid === "string" && SID.test(m.sid)) {
          a.sid = m.sid;
          for (const o of this.ctx.getWebSockets()) { if (o === ws) continue; const b = o.deserializeAttachment(); if (b && b.sid === a.sid) this.drop(o); }
        }
        ws.serializeAttachment(a); this.send({ k: "hi", id: a.id, name: a.name, kind: a.kind }, null, ws); return;
      }
      case "st": {
        const nsp = ROOMS.test(m.sp) ? m.sp : "", old = sp;
        a.st = { sp: nsp, x: num(m.x, -1e5, 1e5), y: num(m.y, -1e3, 1e3), z: num(m.z, -1e5, 1e5), r: num(m.r, -10, 10), v: m.v ? 1 : 0, b: m.b ? 1 : 0, rt: num(m.rt, 0, 1e4), seat: Math.max(0, Math.min(20, m.seat | 0)) };
        ws.serializeAttachment(a);
        this.send(Object.assign({ k: "st", id: a.id }, a.st), b => b.st && (b.st.sp === nsp || b.st.sp === old), ws); return;
      }
      case "em": { const e = EMOTES.indexOf(m.e); if (e < 0) return; this.send({ k: "em", id: a.id, e: EMOTES[e] }, here, ws); return; }
      case "ph": { const n = m.n | 0; if (!PHRASES[n]) return; this.send({ k: "ph", id: a.id, n }, here, ws); return; }
      case "sw": this.send({ k: "sw", id: a.id }, here, ws); return;
      case "pn": { const n = Math.round(+m.n); if (n >= 36 && n <= 96) this.send({ k: "pn", id: a.id, n }, here, ws); return; }
      case "fx": { if (typeof m.n !== "string" || m.n.length > 16) return; const d = m.d && typeof m.d === "object" ? m.d : {}; if (JSON.stringify(d).length > 400) return; this.send({ k: "fx", id: a.id, n: m.n, sp, d }, here, ws); return; }
      case "cin": {   // the Chapel's shared screening: which video, and when it started (server time)
        const i = m.i | 0; if (i < 0 || i > 60) return;
        const last = (await this.ctx.storage.get("cin")) || { at: 0 }; if (t - last.at < 4000) return;   // no rapid flipping
        const cin = { i, at: t }; await this.ctx.storage.put("cin", cin); this.send(Object.assign({ k: "cin", id: a.id }, cin)); return;
      }
      case "chat": {   // room chat: only people in the same room see it; one message every 1.5 s
        if (t - (a.c || 0) < 1500 || !a.name) return; a.c = t; ws.serializeAttachment(a);
        const txt = say(m.t); if (!txt) return; this.send({ k: "chat", id: a.id, t: txt }, here, ws); return;
      }
      case "ping": ws.send(JSON.stringify({ k: "pong", now: t })); return;
      default: return;   // anything else is dropped
    }
  }

  drop(ws) { this.bye(ws); try { ws.serializeAttachment(null); } catch (e) {} try { ws.close(4000, "replaced"); } catch (e) {} }
  sweep() {
    const t = Date.now();
    for (const ws of this.ctx.getWebSockets()) { const a = ws.deserializeAttachment(); if (a && a.t && t - a.t > STALE) this.drop(ws); }
  }
  bye(ws) { const a = ws.deserializeAttachment(); if (a && a.id) this.send({ k: "bye", id: a.id }, null, ws); }
  async webSocketClose(ws, code) { this.bye(ws); try { ws.close(code === 1005 ? 1000 : code, "bye"); } catch (e) {} }
  async webSocketError(ws) { this.bye(ws); }
}
