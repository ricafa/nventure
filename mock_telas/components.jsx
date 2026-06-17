/* global React */
// Componentes auxiliares compartilhados entre as telas.

const { useState, useEffect, useMemo, useRef } = React;

// ====== Formatação ======
const fmtBRL = (v, opts = {}) => {
  if (v === null || v === undefined || isNaN(v)) return "—";
  const { sign = false, decimals = 2 } = opts;
  const s = Math.abs(v).toLocaleString("pt-BR", {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  });
  const prefix = v < 0 ? "−" : (sign && v > 0 ? "+" : "");
  return prefix + "R$ " + s;
};
const fmtNum = (v, decimals = 2) => {
  if (v === null || v === undefined || isNaN(v)) return "—";
  return Number(v).toLocaleString("pt-BR", {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  });
};
const fmtSignedNum = (v, decimals = 2) => {
  if (v === null || v === undefined || isNaN(v)) return "—";
  const s = Math.abs(v).toLocaleString("pt-BR", {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  });
  if (v === 0) return s;
  return (v < 0 ? "−" : "+") + s;
};
const fmtDate = (s) => {
  if (!s) return "—";
  const [y, m, d] = s.split("-");
  return `${d}/${m}/${y.slice(2)}`;
};
const fmtDateLong = (s) => {
  if (!s) return "—";
  const d = new Date(s + "T00:00:00");
  return d.toLocaleDateString("pt-BR", {
    weekday: "short", day: "2-digit", month: "short", year: "numeric"
  });
};

// ====== Ícones (line, 16px) ======
const Icon = ({ name, size = 14, stroke = 1.5 }) => {
  const common = {
    width: size, height: size,
    viewBox: "0 0 24 24",
    fill: "none", stroke: "currentColor",
    strokeWidth: stroke,
    strokeLinecap: "round",
    strokeLinejoin: "round",
  };
  const paths = {
    dash:    <><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></>,
    box:     <><path d="M3 7l9-4 9 4-9 4-9-4z"/><path d="M3 7v10l9 4 9-4V7"/><path d="M12 11v10"/></>,
    coins:   <><circle cx="9" cy="9" r="6"/><path d="M15 21a6 6 0 0 0 0-12"/><path d="M15 15a6 6 0 0 1-6 6"/></>,
    plus:    <><path d="M12 5v14M5 12h14"/></>,
    list:    <><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></>,
    play:    <><polygon points="6 4 20 12 6 20 6 4"/></>,
    chart:   <><path d="M3 3v18h18"/><path d="M7 14l4-4 4 3 5-7"/></>,
    line:    <><path d="M3 12h4l3-7 4 14 3-7h4"/></>,
    scale:   <><path d="M12 3v18"/><path d="M5 9h14"/><path d="M5 9l-2 5a4 4 0 0 0 8 0z"/><path d="M19 9l-2 5a4 4 0 0 0 8 0z"/></>,
    user:    <><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></>,
    logout:  <><path d="M9 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></>,
    search:  <><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></>,
    filter:  <><path d="M3 5h18l-7 9v6l-4-2v-4z"/></>,
    upload:  <><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5"/><path d="M12 3v12"/></>,
    download:<><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></>,
    refresh: <><path d="M3 12a9 9 0 0 1 15.5-6.3L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-15.5 6.3L3 16"/><path d="M3 21v-5h5"/></>,
    check:   <><path d="M5 12l5 5L20 7"/></>,
    x:       <><path d="M6 6l12 12M6 18L18 6"/></>,
    chevron: <><path d="M9 18l6-6-6-6"/></>,
    eye:     <><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></>,
    trash:   <><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></>,
    edit:    <><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4z"/></>,
    bolt:    <><path d="M13 2L3 14h9l-1 8 10-12h-9z"/></>,
    info:    <><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></>,
    warn:    <><path d="M10.3 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/></>,
    clock:   <><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></>,
    sigma:   <><path d="M5 4h14l-9 8 9 8H5"/></>,
    dot:     <><circle cx="12" cy="12" r="4"/></>,
  };
  return <svg {...common}>{paths[name] || null}</svg>;
};

// ====== Sparkline mini chart ======
function Sparkline({ data, width = 100, height = 28, color = "var(--amber)" }) {
  if (!data || data.length < 2) return null;
  const min = Math.min(...data);
  const max = Math.max(...data);
  const range = max - min || 1;
  const stepX = width / (data.length - 1);
  const points = data.map((v, i) => {
    const x = i * stepX;
    const y = height - ((v - min) / range) * height;
    return `${x.toFixed(1)},${y.toFixed(1)}`;
  }).join(" ");
  const last = data[data.length - 1];
  const lastX = (data.length - 1) * stepX;
  const lastY = height - ((last - min) / range) * height;
  return (
    <svg className="spark" viewBox={`0 0 ${width} ${height}`} preserveAspectRatio="none">
      <polyline points={points} fill="none" stroke={color} strokeWidth="1.4"
        vectorEffect="non-scaling-stroke" strokeLinejoin="round" strokeLinecap="round" />
      <circle cx={lastX} cy={lastY} r="2" fill={color} />
    </svg>
  );
}

// ====== Line chart (P&L) ======
function LineChart({ series, width = 920, height = 240, yFmt = (v) => fmtBRL(v, { decimals: 0 }) }) {
  if (!series || !series.length) return null;
  const all = series.flatMap((s) => s.values);
  let min = Math.min(...all, 0);
  let max = Math.max(...all, 0);
  if (min === max) max = min + 1;
  const padY = (max - min) * 0.08;
  min -= padY; max += padY;
  const padL = 72, padR = 12, padT = 14, padB = 28;
  const w = width - padL - padR;
  const h = height - padT - padB;
  const n = series[0].values.length;
  const x = (i) => padL + (i / (n - 1)) * w;
  const y = (v) => padT + h - ((v - min) / (max - min)) * h;
  const ticks = [];
  for (let i = 0; i <= 4; i++) {
    const v = min + (max - min) * (i / 4);
    ticks.push({ v, y: y(v) });
  }
  // zero line
  const zeroY = (min <= 0 && max >= 0) ? y(0) : null;
  return (
    <svg viewBox={`0 0 ${width} ${height}`} width="100%" style={{ display: "block" }}>
      {/* grid */}
      {ticks.map((t, i) => (
        <g key={i}>
          <line x1={padL} x2={width - padR} y1={t.y} y2={t.y} stroke="var(--line-0)" strokeWidth="1" />
          <text x={padL - 8} y={t.y + 3.5} textAnchor="end" fontSize="10" fill="var(--fg-3)"
                fontFamily="JetBrains Mono">{yFmt(t.v)}</text>
        </g>
      ))}
      {zeroY !== null && (
        <line x1={padL} x2={width - padR} y1={zeroY} y2={zeroY} stroke="var(--line-1)" strokeWidth="1" strokeDasharray="3 3" />
      )}
      {/* x ticks */}
      {series[0].labels && series[0].labels.map((l, i) => {
        const step = Math.ceil(n / 8);
        if (i % step !== 0 && i !== n - 1) return null;
        return (
          <text key={i} x={x(i)} y={height - 8} textAnchor="middle" fontSize="10"
                fill="var(--fg-3)" fontFamily="JetBrains Mono">{l}</text>
        );
      })}
      {/* series */}
      {series.map((s, si) => {
        const path = s.values.map((v, i) => `${i === 0 ? "M" : "L"}${x(i)},${y(v)}`).join(" ");
        const area = path + ` L${x(n - 1)},${y(0)} L${x(0)},${y(0)} Z`;
        return (
          <g key={si}>
            {s.fill && <path d={area} fill={s.color} opacity="0.10" />}
            <path d={path} fill="none" stroke={s.color} strokeWidth="1.6"
                  strokeLinejoin="round" strokeLinecap="round" />
            <circle cx={x(n - 1)} cy={y(s.values[n - 1])} r="3" fill={s.color} />
          </g>
        );
      })}
    </svg>
  );
}

// ====== Bar chart (P&L diário) ======
function BarChart({ data, labels, width = 920, height = 200 }) {
  if (!data || !data.length) return null;
  const min = Math.min(...data, 0);
  const max = Math.max(...data, 0);
  const range = max - min || 1;
  const padL = 72, padR = 12, padT = 12, padB = 28;
  const w = width - padL - padR;
  const h = height - padT - padB;
  const bw = (w / data.length) * 0.7;
  const gap = (w / data.length) * 0.3;
  const y0 = padT + h * (max / range);
  const ticks = [];
  for (let i = 0; i <= 4; i++) {
    const v = min + range * (i / 4);
    const yy = padT + h - ((v - min) / range) * h;
    ticks.push({ v, y: yy });
  }
  return (
    <svg viewBox={`0 0 ${width} ${height}`} width="100%" style={{ display: "block" }}>
      {ticks.map((t, i) => (
        <g key={i}>
          <line x1={padL} x2={width - padR} y1={t.y} y2={t.y} stroke="var(--line-0)" strokeWidth="1" />
          <text x={padL - 8} y={t.y + 3.5} textAnchor="end" fontSize="10" fill="var(--fg-3)"
                fontFamily="JetBrains Mono">{fmtBRL(t.v, { decimals: 0 })}</text>
        </g>
      ))}
      <line x1={padL} x2={width - padR} y1={y0} y2={y0} stroke="var(--line-1)" />
      {data.map((v, i) => {
        const cx = padL + (i + 0.5) * (w / data.length);
        const bx = cx - bw / 2;
        const by = v >= 0 ? padT + h - ((v - min) / range) * h : y0;
        const bh = Math.abs((v / range) * h);
        const color = v >= 0 ? "var(--pos)" : "var(--neg)";
        return (
          <g key={i}>
            <rect x={bx} y={by} width={bw} height={bh} fill={color} opacity="0.9" />
            {(i % Math.ceil(data.length / 8) === 0 || i === data.length - 1) && (
              <text x={cx} y={height - 8} textAnchor="middle" fontSize="10"
                    fill="var(--fg-3)" fontFamily="JetBrains Mono">
                {labels[i]}
              </text>
            )}
          </g>
        );
      })}
    </svg>
  );
}

// ====== Pill helpers ======
const InstrumentoPill = ({ tipo }) => {
  const map = {
    FUTURO: "amber", NDF: "info", OPCAO: "muted", OTC: "muted"
  };
  return <span className={"pill " + (map[tipo] || "muted")}>{tipo}</span>;
};
const LadoPill = ({ lado }) => (
  <span className={"pill " + (lado === "COMPRADO" ? "pos" : "neg")}>
    {lado === "COMPRADO" ? "C ↑" : "V ↓"}
  </span>
);

// ====== Empty state ======
const Empty = ({ icon = "info", title, hint }) => (
  <div style={{ padding: "36px 16px", textAlign: "center", color: "var(--fg-2)" }}>
    <div style={{ color: "var(--fg-3)", marginBottom: 8 }}>
      <Icon name={icon} size={22} />
    </div>
    <div style={{ color: "var(--fg-0)", fontWeight: 500 }}>{title}</div>
    {hint && <div style={{ fontSize: 12, marginTop: 4 }}>{hint}</div>}
  </div>
);

// ====== Drawer / Modal ======
function Modal({ onClose, children, title, footer }) {
  return (
    <div className="overlay" onClick={onClose}>
      <div className="modal" onClick={(e) => e.stopPropagation()}>
        <div className="panel-head" style={{ position: "sticky", top: 0, background: "var(--bg-1)", zIndex: 2 }}>
          <h3>{title}</h3>
          <button className="btn xs ghost" onClick={onClose}><Icon name="x" /></button>
        </div>
        <div style={{ padding: 18 }}>{children}</div>
        {footer && (
          <div style={{ padding: 14, borderTop: "1px solid var(--line-0)", display: "flex", justifyContent: "flex-end", gap: 8, background: "var(--bg-1)" }}>
            {footer}
          </div>
        )}
      </div>
    </div>
  );
}

// ====== Sidebar nav config ======
const NAV = [
  { group: "Mesa", items: [
    { id: "dashboard", label: "Dashboard", icon: "dash" },
  ]},
  { group: "Cadastros", items: [
    { id: "produtos", label: "Produtos", icon: "box" },
    { id: "precos", label: "Preços de referência", icon: "coins" },
    { id: "nova-posicao", label: "Nova posição", icon: "plus" },
    { id: "posicoes", label: "Posições", icon: "list" },
  ]},
  { group: "Processamento", items: [
    { id: "motor", label: "Motor MtM", icon: "play" },
  ]},
  { group: "Relatórios", items: [
    { id: "rel-posicao", label: "Posição aberta", icon: "list" },
    { id: "rel-pl", label: "P&L diário e acumulado", icon: "chart" },
    { id: "rel-exposicao", label: "Exposição líquida", icon: "scale" },
  ]},
];

Object.assign(window, {
  Icon, Sparkline, LineChart, BarChart,
  fmtBRL, fmtNum, fmtSignedNum, fmtDate, fmtDateLong,
  InstrumentoPill, LadoPill, Empty, Modal, NAV,
});
