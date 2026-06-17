/* global React, window */
const { useState, useMemo, useEffect } = React;
const D2 = window.__APP_DATA__;

// ============================================================
// PayoffChart — P&L no vencimento de uma estrutura de opções
// ============================================================
function PayoffChart({ pernas, spotRef }) {
  const strikes = pernas.map((p) => +p.strike || 0).filter((s) => s > 0);
  const minK = strikes.length ? Math.min(...strikes) : spotRef;
  const maxK = strikes.length ? Math.max(...strikes) : spotRef;
  const lo = Math.min(minK, spotRef) * 0.85;
  const hi = Math.max(maxK, spotRef) * 1.15;
  const N = 80;
  const xs = Array.from({ length: N + 1 }, (_, i) => lo + (i / N) * (hi - lo));

  const payoffAt = (S) => pernas.reduce((sum, p) => {
    const sinal = p.lado === "COMPRADO" ? 1 : -1;
    const K = +p.strike || 0;
    const vi = p.tipo_opcao === "CALL" ? Math.max(S - K, 0) : Math.max(K - S, 0);
    return sum + (vi - (+p.premio || 0)) * (+p.quantidade || 0) * sinal;
  }, 0);

  const ys = xs.map(payoffAt);
  const yMin = Math.min(...ys, 0);
  const yMax = Math.max(...ys, 0);
  const pad = (yMax - yMin) * 0.12 || 1;
  const yLo = yMin - pad;
  const yHi = yMax + pad;

  const W = 320, H = 180;
  const padL = 8, padR = 8, padT = 10, padB = 22;
  const w = W - padL - padR;
  const h = H - padT - padB;
  const sx = (S) => padL + ((S - lo) / (hi - lo)) * w;
  const sy = (P) => padT + h - ((P - yLo) / (yHi - yLo)) * h;
  const zeroY = sy(0);

  // path split positive/negative
  const pathPos = [];
  const pathNeg = [];
  ys.forEach((y, i) => {
    const x = sx(xs[i]);
    const yy = sy(y);
    (y >= 0 ? pathPos : pathNeg).push([x, yy, y, xs[i]]);
  });

  // build area paths above/below zero by clipping with zeroY
  const buildArea = (above) => {
    const pts = ys.map((y, i) => [sx(xs[i]), sy(y), y]);
    const segs = [];
    let cur = [];
    pts.forEach(([x, y, v], i) => {
      const inside = above ? v >= 0 : v <= 0;
      if (inside) cur.push([x, y]);
      else if (cur.length) { segs.push(cur); cur = []; }
    });
    if (cur.length) segs.push(cur);
    return segs.map((s) => {
      const d = s.map(([x, y], i) => `${i === 0 ? "M" : "L"}${x.toFixed(1)},${y.toFixed(1)}`).join(" ");
      const first = s[0], last = s[s.length - 1];
      return `${d} L${last[0].toFixed(1)},${zeroY.toFixed(1)} L${first[0].toFixed(1)},${zeroY.toFixed(1)} Z`;
    }).join(" ");
  };

  const linePath = ys.map((y, i) => `${i === 0 ? "M" : "L"}${sx(xs[i]).toFixed(1)},${sy(y).toFixed(1)}`).join(" ");

  // find breakevens (sign changes)
  const breakevens = [];
  for (let i = 1; i < ys.length; i++) {
    if ((ys[i - 1] < 0 && ys[i] >= 0) || (ys[i - 1] > 0 && ys[i] <= 0)) {
      const t = ys[i - 1] / (ys[i - 1] - ys[i]);
      breakevens.push(xs[i - 1] + t * (xs[i] - xs[i - 1]));
    }
  }

  const maxLoss = Math.min(...ys);
  const maxGain = Math.max(...ys);

  return (
    <div>
      <svg viewBox={`0 0 ${W} ${H}`} width="100%" style={{ display: "block" }}>
        {/* zero line */}
        <line x1={padL} x2={W - padR} y1={zeroY} y2={zeroY} stroke="var(--line-1)" strokeDasharray="2 3" />

        {/* strike marks */}
        {strikes.map((K, i) => (
          <g key={i}>
            <line x1={sx(K)} x2={sx(K)} y1={padT} y2={padT + h} stroke="var(--line-0)" strokeDasharray="2 4" />
            <text x={sx(K)} y={padT - 2} textAnchor="middle" fontSize="9" fill="var(--fg-3)" fontFamily="JetBrains Mono">K{fmtNum(K, 0)}</text>
          </g>
        ))}

        {/* spot marker */}
        <line x1={sx(spotRef)} x2={sx(spotRef)} y1={padT} y2={padT + h} stroke="var(--amber)" strokeWidth="1" opacity="0.6" />
        <text x={sx(spotRef)} y={H - 6} textAnchor="middle" fontSize="9" fill="var(--amber)" fontFamily="JetBrains Mono">spot</text>

        {/* areas */}
        <path d={buildArea(true)} fill="var(--pos)" opacity="0.15" />
        <path d={buildArea(false)} fill="var(--neg)" opacity="0.15" />

        {/* line */}
        <path d={linePath} fill="none" stroke="var(--fg-0)" strokeWidth="1.6" strokeLinejoin="round" strokeLinecap="round" />

        {/* breakevens */}
        {breakevens.map((be, i) => (
          <g key={i}>
            <circle cx={sx(be)} cy={zeroY} r="3" fill="var(--amber)" stroke="var(--bg-1)" strokeWidth="1" />
          </g>
        ))}

        {/* axis labels */}
        <text x={padL} y={H - 6} fontSize="9" fill="var(--fg-3)" fontFamily="JetBrains Mono">{fmtNum(lo, 0)}</text>
        <text x={W - padR} y={H - 6} fontSize="9" fill="var(--fg-3)" fontFamily="JetBrains Mono" textAnchor="end">{fmtNum(hi, 0)}</text>
      </svg>
      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: 8, marginTop: 10, fontSize: 11 }}>
        <div>
          <div style={{ color: "var(--fg-3)", fontSize: 10, textTransform: "uppercase", letterSpacing: "0.08em" }}>Max ganho</div>
          <div className="mono" style={{ color: "var(--pos)", fontWeight: 600, fontSize: 12 }}>
            {maxGain > 1e6 ? "ilimitado" : fmtBRL(maxGain, { decimals: 0 })}
          </div>
        </div>
        <div>
          <div style={{ color: "var(--fg-3)", fontSize: 10, textTransform: "uppercase", letterSpacing: "0.08em" }}>Max perda</div>
          <div className="mono" style={{ color: "var(--neg)", fontWeight: 600, fontSize: 12 }}>
            {maxLoss < -1e6 ? "ilimitada" : fmtBRL(maxLoss, { decimals: 0 })}
          </div>
        </div>
        <div>
          <div style={{ color: "var(--fg-3)", fontSize: 10, textTransform: "uppercase", letterSpacing: "0.08em" }}>Breakeven{breakevens.length > 1 ? "s" : ""}</div>
          <div className="mono" style={{ color: "var(--amber)", fontWeight: 600, fontSize: 12 }}>
            {breakevens.length ? breakevens.map((b) => fmtNum(b, 0)).join(" / ") : "—"}
          </div>
        </div>
      </div>
    </div>
  );
}

// ============================================================
// NOVA POSIÇÃO — formulário dinâmico
// ============================================================
function NovaPosicaoScreen() {
  const [tipo, setTipo] = useState("FUTURO");
  const [produto, setProduto] = useState(1);
  const [mercado, setMercado] = useState("BOLSA");
  const [lado, setLado] = useState("COMPRADO");
  const [qtd, setQtd] = useState("100");
  const [dEntrada, setDEntrada] = useState(D2.HOJE);
  const [dVenc, setDVenc] = useState("2026-09-15");
  const [contraparte, setContraparte] = useState("");
  const [obs, setObs] = useState("");

  // Específicos
  const [precoEntrada, setPrecoEntrada] = useState("1418.00");
  const [codigoContrato, setCodigoContrato] = useState("ZSU24");
  const [taxaContratada, setTaxaContratada] = useState("5.18");
  const [valorNocional, setValorNocional] = useState("2400000");
  const [moedaNocional, setMoedaNocional] = useState("USD");
  const [tipoOpcao, setTipoOpcao] = useState("CALL"); // legado (mantido para serialização de 1 perna)
  const [estilo, setEstilo] = useState("EUROPEIA");
  const [pernas, setPernas] = useState([
    { id: 1, lado: "COMPRADO", tipo_opcao: "CALL", strike: "220.00", premio: "4.85", quantidade: "80" },
  ]);
  const [strike, setStrike] = useState("220.00");   // legado
  const [premio, setPremio] = useState("4.85");     // legado

  const addPerna = () => setPernas((ps) => {
    const last = ps[ps.length - 1] || {};
    return [...ps, {
      id: Date.now() + Math.random(),
      lado: last.lado === "COMPRADO" ? "VENDIDO" : "COMPRADO",
      tipo_opcao: last.tipo_opcao || "CALL",
      strike: "",
      premio: "",
      quantidade: last.quantidade || "80",
    }];
  });
  const removePerna = (id) => setPernas((ps) => ps.length > 1 ? ps.filter((p) => p.id !== id) : ps);
  const updatePerna = (id, field, value) => setPernas((ps) => ps.map((p) => p.id === id ? { ...p, [field]: value } : p));

  const loadTemplate = (key) => {
    const atmRaw = D2.precoEm(produto, D2.HOJE)?.preco_fechamento || 220;
    const atm = +atmRaw.toFixed(2);
    const Q = "80";
    const T = {
      call:        [{ lado: "COMPRADO", tipo_opcao: "CALL", strike: atm.toFixed(2), premio: "4.85", quantidade: Q }],
      put:         [{ lado: "COMPRADO", tipo_opcao: "PUT",  strike: atm.toFixed(2), premio: "4.85", quantidade: Q }],
      call_spread: [
        { lado: "COMPRADO", tipo_opcao: "CALL", strike: atm.toFixed(2),          premio: "5.20", quantidade: Q },
        { lado: "VENDIDO",  tipo_opcao: "CALL", strike: (atm * 1.05).toFixed(2),  premio: "2.40", quantidade: Q },
      ],
      put_spread:  [
        { lado: "COMPRADO", tipo_opcao: "PUT",  strike: atm.toFixed(2),          premio: "5.10", quantidade: Q },
        { lado: "VENDIDO",  tipo_opcao: "PUT",  strike: (atm * 0.95).toFixed(2),  premio: "2.30", quantidade: Q },
      ],
      straddle:    [
        { lado: "COMPRADO", tipo_opcao: "CALL", strike: atm.toFixed(2),          premio: "5.20", quantidade: Q },
        { lado: "COMPRADO", tipo_opcao: "PUT",  strike: atm.toFixed(2),          premio: "5.10", quantidade: Q },
      ],
      strangle:    [
        { lado: "COMPRADO", tipo_opcao: "CALL", strike: (atm * 1.05).toFixed(2), premio: "2.40", quantidade: Q },
        { lado: "COMPRADO", tipo_opcao: "PUT",  strike: (atm * 0.95).toFixed(2), premio: "2.30", quantidade: Q },
      ],
      iron_condor: [
        { lado: "VENDIDO",  tipo_opcao: "PUT",  strike: (atm * 0.95).toFixed(2), premio: "2.30", quantidade: Q },
        { lado: "COMPRADO", tipo_opcao: "PUT",  strike: (atm * 0.90).toFixed(2), premio: "1.10", quantidade: Q },
        { lado: "VENDIDO",  tipo_opcao: "CALL", strike: (atm * 1.05).toFixed(2), premio: "2.40", quantidade: Q },
        { lado: "COMPRADO", tipo_opcao: "CALL", strike: (atm * 1.10).toFixed(2), premio: "1.20", quantidade: Q },
      ],
    };
    const tmpl = T[key] || T.call;
    setPernas(tmpl.map((p, i) => ({ id: Date.now() + i, ...p })));
  };

  const estruturaName = (() => {
    const ps = pernas;
    const n = ps.length;
    if (n === 1) {
      const p = ps[0];
      return (p.lado === "COMPRADO" ? "Long " : "Short ") + (p.tipo_opcao === "CALL" ? "Call" : "Put");
    }
    if (n === 2) {
      const [a, b] = ps;
      const sameTipo = a.tipo_opcao === b.tipo_opcao;
      const sameLado = a.lado === b.lado;
      const sameStrike = Math.abs((+a.strike) - (+b.strike)) < 0.001;
      if (sameTipo && !sameLado) return a.tipo_opcao === "CALL" ? "Vertical Call Spread" : "Vertical Put Spread";
      if (!sameTipo && sameLado && sameStrike) return a.lado === "COMPRADO" ? "Long Straddle" : "Short Straddle";
      if (!sameTipo && sameLado && !sameStrike) return a.lado === "COMPRADO" ? "Long Strangle" : "Short Strangle";
      if (!sameTipo && !sameLado) return "Collar / Risk Reversal";
      return "Estrutura 2 pernas";
    }
    if (n === 3) return "Estrutura 3 pernas";
    if (n === 4) {
      const calls = ps.filter((p) => p.tipo_opcao === "CALL").length;
      const puts  = ps.filter((p) => p.tipo_opcao === "PUT").length;
      if (calls === 2 && puts === 2) return "Iron Condor";
      return "Butterfly / 4 pernas";
    }
    return `Estrutura customizada (${n} pernas)`;
  })();
  const [indexador, setIndexador] = useState("CEPEA_MILHO_ESALQ");
  const [premioOtc, setPremioOtc] = useState("-0.50");

  const prod = D2.PRODUTOS.find((p) => p.id === produto);

  // preview MtM
  const previewMtm = useMemo(() => {
    const precoAtual = D2.precoEm(produto, D2.HOJE);
    if (!precoAtual) return null;
    let mtmOrig = 0;
    if (tipo === "OPCAO") {
      for (const perna of pernas) {
        const sinal = perna.lado === "COMPRADO" ? 1 : -1;
        const k = +perna.strike || 0;
        const spot = precoAtual.preco_fechamento;
        const vi = perna.tipo_opcao === "CALL" ? Math.max(spot - k, 0) : Math.max(k - spot, 0);
        mtmOrig += (vi - (+perna.premio || 0)) * (+perna.quantidade || 0) * sinal;
      }
    } else {
      const pseudoPos = {
        lado, quantidade: +qtd || 0, instrumento: tipo,
        extra: {
          preco_entrada: +precoEntrada || 0,
          taxa_contratada: +taxaContratada || 0,
          valor_nocional: +valorNocional || 0,
          tipo_opcao: tipoOpcao,
          strike: +strike || 0,
          premio_pago: +premio || 0,
          indexador,
          premio_otc: +premioOtc || 0,
        },
      };
      mtmOrig = D2.calcularMtmMoedaOrig(pseudoPos, precoAtual.preco_fechamento);
    }
    return prod.moeda_cotacao === "USD" ? mtmOrig * precoAtual.cambio_brl : mtmOrig;
  }, [tipo, lado, qtd, produto, precoEntrada, taxaContratada, valorNocional, tipoOpcao, strike, premio, pernas, premioOtc, indexador, prod]);

  return (
    <div className="page" data-screen-label="05 Nova Posicao">
      <div className="page-head">
        <div>
          <div className="crumb">Cadastros · Nova posição</div>
          <h1>Lançar posição em derivativo</h1>
          <div className="sub">Formulário dinâmico · campos se adaptam ao tipo de instrumento</div>
        </div>
        <div style={{ display: "flex", gap: 8 }}>
          <button className="btn">Cancelar</button>
          <button className="btn primary"><Icon name="check" /> Salvar posição</button>
        </div>
      </div>

      <div style={{ display: "grid", gridTemplateColumns: "1fr 360px", gap: 14 }}>
        <div className="panel">
          <div className="panel-head">
            <h3>Dados da posição</h3>
            <div className="meta">POST /posicoes/{tipo.toLowerCase()}</div>
          </div>
          <div className="panel-body" style={{ display: "grid", gap: 18 }}>
            {/* Tipo */}
            <div>
              <div style={{ fontSize: 11, color: "var(--fg-3)", textTransform: "uppercase", letterSpacing: "0.08em", fontWeight: 600, marginBottom: 8 }}>
                Tipo de instrumento
              </div>
              <div style={{ display: "grid", gridTemplateColumns: "repeat(4, 1fr)", gap: 8 }}>
                {[
                  { t: "FUTURO", desc: "Preço × qtde × sinal" },
                  { t: "NDF", desc: "Taxa × nocional × sinal" },
                  { t: "OPCAO", desc: "Valor intrínseco − prêmio" },
                  { t: "OTC", desc: "(preço + prêmio) × qtde" },
                ].map(({ t, desc }) => (
                  <button key={t}
                    type="button"
                    onClick={() => setTipo(t)}
                    style={{
                      background: tipo === t ? "var(--bg-3)" : "var(--bg-2)",
                      border: "1px solid " + (tipo === t ? "var(--amber-dim)" : "var(--line-1)"),
                      color: tipo === t ? "var(--fg-0)" : "var(--fg-1)",
                      padding: "12px 12px",
                      borderRadius: 4,
                      cursor: "pointer",
                      textAlign: "left",
                      font: "inherit",
                    }}>
                    <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
                      <span className={"pill " + (t === "FUTURO" ? "amber" : t === "NDF" ? "info" : "muted")}>{t}</span>
                      {tipo === t && <Icon name="check" size={12} />}
                    </div>
                    <div style={{ fontSize: 10.5, color: "var(--fg-3)", fontFamily: "var(--mono)", marginTop: 6 }}>
                      {desc}
                    </div>
                  </button>
                ))}
              </div>
            </div>

            {/* Comuns */}
            <div>
              <div style={{ fontSize: 11, color: "var(--fg-3)", textTransform: "uppercase", letterSpacing: "0.08em", fontWeight: 600, marginBottom: 10 }}>
                Atributos comuns (tabela posicao)
              </div>
              <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 14 }}>
                <div className="field">
                  <label>Produto subjacente<span className="req">*</span></label>
                  <select value={produto} onChange={(e) => setProduto(+e.target.value)}>
                    {D2.PRODUTOS.filter(p => p.ativo).map((p) => (
                      <option key={p.id} value={p.id}>{p.nome} · {p.bolsa_ref}</option>
                    ))}
                  </select>
                </div>
                <div className="field">
                  <label>Mercado<span className="req">*</span></label>
                  <div className="seg" style={{ width: "fit-content" }}>
                    <button type="button" className={mercado === "BOLSA" ? "on" : ""} onClick={() => setMercado("BOLSA")}>BOLSA</button>
                    <button type="button" className={mercado === "BALCAO" ? "on" : ""} onClick={() => setMercado("BALCAO")}>BALCÃO</button>
                  </div>
                </div>
                <div className="field">
                  <label>Lado<span className="req">*</span></label>
                  <div className="seg" style={{ width: "fit-content" }}>
                    <button type="button" className={lado === "COMPRADO" ? "on" : ""} onClick={() => setLado("COMPRADO")}>COMPRADO ↑</button>
                    <button type="button" className={lado === "VENDIDO" ? "on" : ""} onClick={() => setLado("VENDIDO")}>VENDIDO ↓</button>
                  </div>
                </div>
                <div className="field">
                  <label>Quantidade<span className="req">*</span></label>
                  <input className="mono" value={qtd} onChange={(e) => setQtd(e.target.value)} />
                  <div className="help">RN-001 · quantidade &gt; 0 · sinal vem do lado</div>
                </div>
                <div className="field">
                  <label>Data de entrada<span className="req">*</span></label>
                  <input type="date" value={dEntrada} onChange={(e) => setDEntrada(e.target.value)} />
                </div>
                <div className="field">
                  <label>Data de vencimento<span className="req">*</span></label>
                  <input type="date" value={dVenc} onChange={(e) => setDVenc(e.target.value)} />
                  <div className="help">RN-002 · vencimento &gt; entrada</div>
                </div>
              </div>
            </div>

            {/* Específicos */}
            <div>
              <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", marginBottom: 10 }}>
                <div style={{ fontSize: 11, color: "var(--fg-3)", textTransform: "uppercase", letterSpacing: "0.08em", fontWeight: 600 }}>
                  Campos específicos · posicao_{tipo.toLowerCase()}
                </div>
                <span className="pill amber">{tipo}</span>
              </div>

              {tipo === "FUTURO" && (
                <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 14 }}>
                  <div className="field">
                    <label>Preço médio de entrada<span className="req">*</span></label>
                    <input className="mono" value={precoEntrada} onChange={(e) => setPrecoEntrada(e.target.value)} />
                  </div>
                  <div className="field">
                    <label>Código do contrato<span className="req">*</span></label>
                    <input className="mono" value={codigoContrato} onChange={(e) => setCodigoContrato(e.target.value)} />
                    <div className="help">ex.: ZSU24 = Soja CBOT setembro/24</div>
                  </div>
                </div>
              )}

              {tipo === "NDF" && (
                <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: 14 }}>
                  <div className="field">
                    <label>Taxa contratada<span className="req">*</span></label>
                    <input className="mono" value={taxaContratada} onChange={(e) => setTaxaContratada(e.target.value)} />
                  </div>
                  <div className="field">
                    <label>Valor nocional<span className="req">*</span></label>
                    <input className="mono" value={valorNocional} onChange={(e) => setValorNocional(e.target.value)} />
                    <div className="help">RN-005 · &gt; 0</div>
                  </div>
                  <div className="field">
                    <label>Moeda do nocional<span className="req">*</span></label>
                    <select value={moedaNocional} onChange={(e) => setMoedaNocional(e.target.value)}>
                      <option>USD</option><option>BRL</option><option>EUR</option>
                    </select>
                  </div>
                </div>
              )}

              {tipo === "OPCAO" && (
                <div>
                  {/* Estrutura header */}
                  <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", marginBottom: 12, flexWrap: "wrap", gap: 10 }}>
                    <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
                      <span className="pill amber">{estruturaName}</span>
                      <span style={{ color: "var(--fg-3)", fontSize: 11, fontFamily: "var(--mono)" }}>
                        {pernas.length} {pernas.length === 1 ? "perna" : "pernas"} · cadastrada como uma posição com {pernas.length} sub-registros
                      </span>
                    </div>
                    <div className="field" style={{ flexDirection: "row", alignItems: "center", gap: 8 }}>
                      <label style={{ margin: 0 }}>Estilo</label>
                      <div className="seg" style={{ width: "fit-content" }}>
                        <button type="button" className={estilo === "EUROPEIA" ? "on" : ""} onClick={() => setEstilo("EUROPEIA")}>EUROPÉIA</button>
                        <button type="button" className={estilo === "AMERICANA" ? "on" : ""} onClick={() => setEstilo("AMERICANA")}>AMERICANA</button>
                      </div>
                    </div>
                  </div>

                  {/* Templates */}
                  <div style={{ display: "flex", gap: 6, alignItems: "center", flexWrap: "wrap", marginBottom: 12, padding: "8px 10px", background: "var(--bg-2)", borderRadius: 3, border: "1px solid var(--line-0)" }}>
                    <span style={{ fontSize: 10.5, color: "var(--fg-3)", textTransform: "uppercase", letterSpacing: "0.08em", fontWeight: 600, marginRight: 4 }}>
                      Templates
                    </span>
                    {[
                      ["call", "Call"], ["put", "Put"],
                      ["call_spread", "Call Spread"], ["put_spread", "Put Spread"],
                      ["straddle", "Straddle"], ["strangle", "Strangle"],
                      ["iron_condor", "Iron Condor"],
                    ].map(([k, label]) => (
                      <button key={k} type="button" className="btn xs" onClick={() => loadTemplate(k)}>{label}</button>
                    ))}
                  </div>

                  {/* Pernas header */}
                  <div style={{
                    display: "grid",
                    gridTemplateColumns: "34px 96px 88px 1fr 1fr 1fr 28px",
                    gap: 8, alignItems: "center",
                    padding: "0 10px 6px",
                    fontSize: 10.5, color: "var(--fg-3)",
                    textTransform: "uppercase", letterSpacing: "0.08em", fontWeight: 600,
                  }}>
                    <div>#</div>
                    <div>Lado</div>
                    <div>Tipo</div>
                    <div style={{ textAlign: "right" }}>Strike</div>
                    <div style={{ textAlign: "right" }}>Prêmio</div>
                    <div style={{ textAlign: "right" }}>Qtde</div>
                    <div></div>
                  </div>

                  {/* Pernas list */}
                  <div style={{ display: "grid", gap: 6 }}>
                    {pernas.map((p, i) => (
                      <div key={p.id} style={{
                        display: "grid",
                        gridTemplateColumns: "34px 96px 88px 1fr 1fr 1fr 28px",
                        gap: 8, alignItems: "center",
                        padding: "8px 10px",
                        background: "var(--bg-2)",
                        border: "1px solid var(--line-0)",
                        borderRadius: 3,
                      }}>
                        <div style={{ fontFamily: "var(--mono)", color: "var(--fg-2)", fontSize: 11, fontWeight: 600, textAlign: "center" }}>L{i + 1}</div>
                        <div className="seg">
                          <button type="button" className={p.lado === "COMPRADO" ? "on" : ""} style={{ padding: "4px 8px", fontSize: 10.5, color: p.lado === "COMPRADO" ? "var(--pos)" : undefined }} onClick={() => updatePerna(p.id, "lado", "COMPRADO")}>+C</button>
                          <button type="button" className={p.lado === "VENDIDO" ? "on" : ""} style={{ padding: "4px 8px", fontSize: 10.5, color: p.lado === "VENDIDO" ? "var(--neg)" : undefined }} onClick={() => updatePerna(p.id, "lado", "VENDIDO")}>−V</button>
                        </div>
                        <div className="seg">
                          <button type="button" className={p.tipo_opcao === "CALL" ? "on" : ""} style={{ padding: "4px 8px", fontSize: 10.5 }} onClick={() => updatePerna(p.id, "tipo_opcao", "CALL")}>CALL</button>
                          <button type="button" className={p.tipo_opcao === "PUT" ? "on" : ""} style={{ padding: "4px 8px", fontSize: 10.5 }} onClick={() => updatePerna(p.id, "tipo_opcao", "PUT")}>PUT</button>
                        </div>
                        <input className="mono" value={p.strike} placeholder="0,00" onChange={(e) => updatePerna(p.id, "strike", e.target.value)} style={{ padding: "6px 8px", fontSize: 12, textAlign: "right" }} />
                        <input className="mono" value={p.premio} placeholder="0,00" onChange={(e) => updatePerna(p.id, "premio", e.target.value)} style={{ padding: "6px 8px", fontSize: 12, textAlign: "right" }} />
                        <input className="mono" value={p.quantidade} placeholder="0" onChange={(e) => updatePerna(p.id, "quantidade", e.target.value)} style={{ padding: "6px 8px", fontSize: 12, textAlign: "right" }} />
                        <button type="button" className="btn xs ghost" disabled={pernas.length === 1} onClick={() => removePerna(p.id)} title="Remover perna" style={{ padding: 4, color: pernas.length === 1 ? "var(--fg-3)" : "var(--neg)" }}>
                          <Icon name="trash" size={12} />
                        </button>
                      </div>
                    ))}
                  </div>

                  <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginTop: 10 }}>
                    <button type="button" className="btn sm" onClick={addPerna}>
                      <Icon name="plus" size={12} /> Adicionar perna
                    </button>
                    <div style={{ fontFamily: "var(--mono)", fontSize: 11, color: "var(--fg-2)" }}>
                      prêmio líquido{" "}
                      <span style={{ color: "var(--fg-0)", fontWeight: 600 }}>
                        {(() => {
                          const liq = pernas.reduce((s, p) => {
                            const sinal = p.lado === "COMPRADO" ? 1 : -1;
                            return s + sinal * (+p.premio || 0) * (+p.quantidade || 0);
                          }, 0);
                          return (liq >= 0 ? "pago " : "recebido ") + fmtNum(Math.abs(liq), 2);
                        })()}
                      </span>
                    </div>
                  </div>

                  <div className="doc-strip" style={{ marginTop: 12, fontSize: 11.5 }}>
                    <b>RN-004</b> Strike &gt; 0 e prêmio ≥ 0 em todas as pernas · MtM agregado soma valor intrínseco − prêmio de cada perna individualmente (polimorfismo aninhado).
                  </div>
                </div>
              )}

              {tipo === "OTC" && (
                <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: 14 }}>
                  <div className="field">
                    <label>Preço de entrada<span className="req">*</span></label>
                    <input className="mono" value={precoEntrada} onChange={(e) => setPrecoEntrada(e.target.value)} />
                  </div>
                  <div className="field">
                    <label>Indexador<span className="req">*</span></label>
                    <select value={indexador} onChange={(e) => setIndexador(e.target.value)}>
                      <option>CEPEA_SOJA</option>
                      <option>CEPEA_MILHO_ESALQ</option>
                      <option>CEPEA_ETANOL</option>
                      <option>CBOT_SOJA</option>
                      <option>ICE_CAFE</option>
                    </select>
                  </div>
                  <div className="field">
                    <label>Prêmio OTC</label>
                    <input className="mono" value={premioOtc} onChange={(e) => setPremioOtc(e.target.value)} />
                    <div className="help">Pode ser negativo</div>
                  </div>
                </div>
              )}
            </div>

            {/* Contraparte + obs */}
            <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 14 }}>
              <div className="field">
                <label>
                  Contraparte
                  {mercado === "BALCAO" && <span className="req">*</span>}
                </label>
                <input value={contraparte} disabled={mercado === "BOLSA"}
                  placeholder={mercado === "BOLSA" ? "—  (bolsa não exige)" : "ex.: Banco Itaú BBA"}
                  onChange={(e) => setContraparte(e.target.value)} />
                <div className="help">RN-003 · obrigatório em BALCÃO</div>
              </div>
              <div className="field">
                <label>Criado por</label>
                <input value={D2.USUARIO.login} disabled className="mono" />
              </div>
              <div className="field" style={{ gridColumn: "1 / 3" }}>
                <label>Observações</label>
                <textarea rows="2" placeholder="Notas livres sobre a posição" value={obs} onChange={(e) => setObs(e.target.value)}></textarea>
              </div>
            </div>
          </div>
        </div>

        {/* Sidebar de preview */}
        <div style={{ display: "flex", flexDirection: "column", gap: 14 }}>
          <div className="panel">
            <div className="panel-head"><h3>Preview do MtM</h3><div className="meta">com preço de hoje</div></div>
            <div className="panel-body">
              <div className="kv-grid">
                <div className="k">Produto</div>
                <div className="v">{prod.nome}</div>
                <div className="k">Preço de hoje</div>
                <div className="v">{fmtNum(D2.precoEm(produto, D2.HOJE)?.preco_fechamento || 0, 4)} <span style={{ color: "var(--fg-3)" }}>{prod.moeda_cotacao}/{prod.unidade}</span></div>
                <div className="k">Câmbio</div>
                <div className="v">{fmtNum(D2.precoEm(produto, D2.HOJE)?.cambio_brl || 0, 4)}</div>
                <div className="k">Sinal</div>
                <div className="v">{lado === "COMPRADO" ? "+1" : "−1"}</div>
              </div>
              <div className="hr"></div>
              <div style={{ fontSize: 11, color: "var(--fg-3)", textTransform: "uppercase", letterSpacing: "0.08em", fontWeight: 600 }}>
                MtM estimado (BRL)
              </div>
              <div style={{ fontFamily: "var(--mono)", fontSize: 28, fontWeight: 500, marginTop: 8, color: (previewMtm || 0) >= 0 ? "var(--pos)" : "var(--neg)" }}>
                {fmtBRL(previewMtm || 0, { decimals: 2 })}
              </div>
              <div style={{ fontSize: 11, color: "var(--fg-3)", marginTop: 6, fontFamily: "var(--mono)" }}>
                não persistido · roda no motor após salvar
              </div>
            </div>
          </div>

          <div className="panel">
            <div className="panel-head"><h3>Fórmula — {tipo}</h3><div className="meta">Posicao.calcular_mtm</div></div>
            <div className="panel-body">
              <pre style={{ margin: 0, fontFamily: "var(--mono)", fontSize: 11.5, color: "var(--fg-1)", whiteSpace: "pre-wrap", lineHeight: 1.65 }}>
{tipo === "FUTURO" && `(preco_mercado − preco_entrada)\n  × quantidade\n  × sinal`}
{tipo === "NDF" && `(preco_mercado − taxa_contratada)\n  × valor_nocional\n  × sinal`}
{tipo === "OPCAO" && `Para cada perna L_i:\n  vi_i = max(spot − K_i, 0)   se CALL\n         max(K_i − spot, 0)   se PUT\n  pl_i = (vi_i − premio_i)\n          × qty_i × sinal_i\n\nMtM = Σ pl_i  ·  ${pernas.length} ${pernas.length === 1 ? "perna" : "pernas"}`}
{tipo === "OTC" && `efetivo = preco_mercado + premio_otc\n(efetivo − preco_entrada)\n  × quantidade\n  × sinal`}
              </pre>
              <div className="hr"></div>
              <div className="doc-strip" style={{ fontSize: 11.5 }}>
                <b>Polimorfismo</b> · cada classe filha implementa calcular_mtm. O motor não conhece o tipo.
              </div>
            </div>
          </div>

          {tipo === "OPCAO" && (
            <div className="panel">
              <div className="panel-head"><h3>Payoff no vencimento</h3><div className="meta">spot atual = {fmtNum(D2.precoEm(produto, D2.HOJE)?.preco_fechamento || 0, 2)}</div></div>
              <div className="panel-body" style={{ padding: 12 }}>
                <PayoffChart pernas={pernas} spotRef={D2.precoEm(produto, D2.HOJE)?.preco_fechamento || 0} />
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

// ============================================================
// POSIÇÕES — listagem
// ============================================================
function PosicoesScreen({ goto }) {
  const [status, setStatus] = useState("ABERTA");
  const [tipoFilter, setTipoFilter] = useState("TODOS");
  const [produtoFilter, setProdutoFilter] = useState("TODOS");
  const [busca, setBusca] = useState("");
  const [sel, setSel] = useState(null);

  const filtered = D2.POSICOES.filter((p) => {
    if (status !== "TODAS" && p.status !== status) return false;
    if (tipoFilter !== "TODOS" && p.instrumento !== tipoFilter) return false;
    if (produtoFilter !== "TODOS" && p.produto_id !== +produtoFilter) return false;
    if (busca) {
      const hay = JSON.stringify(p).toLowerCase();
      if (!hay.includes(busca.toLowerCase())) return false;
    }
    return true;
  });

  return (
    <div className="page" data-screen-label="06 Posicoes">
      <div className="page-head">
        <div>
          <div className="crumb">Cadastros · Posições</div>
          <h1>Posições do portfólio</h1>
          <div className="sub">{filtered.length} de {D2.POSICOES.length} registros · MtM atualizado em {D2.HOJE}</div>
        </div>
        <div style={{ display: "flex", gap: 8 }}>
          <button className="btn"><Icon name="download" /> Exportar</button>
          <button className="btn primary" onClick={() => goto("nova-posicao")}>
            <Icon name="plus" /> Nova posição
          </button>
        </div>
      </div>

      <div className="panel">
        <div className="panel-head" style={{ flexWrap: "wrap", gap: 10 }}>
          <div style={{ display: "flex", alignItems: "center", gap: 10, flexWrap: "wrap" }}>
            <div style={{ position: "relative", width: 240 }}>
              <input type="text" placeholder="Buscar contraparte, código, obs…" value={busca} onChange={(e) => setBusca(e.target.value)} style={{ paddingLeft: 30 }} />
              <span style={{ position: "absolute", left: 9, top: 8, color: "var(--fg-3)" }}><Icon name="search" /></span>
            </div>
            <div className="seg">
              {["ABERTA", "ENCERRADA", "VENCIDA", "TODAS"].map((s) => (
                <button key={s} className={status === s ? "on" : ""} onClick={() => setStatus(s)}>{s}</button>
              ))}
            </div>
            <select value={tipoFilter} onChange={(e) => setTipoFilter(e.target.value)} style={{ width: 140 }}>
              <option value="TODOS">Todos tipos</option>
              <option>FUTURO</option><option>NDF</option><option>OPCAO</option><option>OTC</option>
            </select>
            <select value={produtoFilter} onChange={(e) => setProdutoFilter(e.target.value)} style={{ width: 180 }}>
              <option value="TODOS">Todos produtos</option>
              {D2.PRODUTOS.map((p) => <option key={p.id} value={p.id}>{p.nome}</option>)}
            </select>
          </div>
        </div>

        <table className="t">
          <thead><tr>
            <th style={{ width: 60 }}>#</th>
            <th>Produto</th>
            <th>Tipo</th>
            <th>Lado</th>
            <th className="num">Quantidade</th>
            <th>Entrada → Venc.</th>
            <th>Contraparte</th>
            <th className="num">MtM hoje</th>
            <th className="num">Δ D−1</th>
            <th>Status</th>
            <th style={{ width: 40 }}></th>
          </tr></thead>
          <tbody>
            {filtered.map((p) => {
              const prod = D2.PRODUTOS.find((x) => x.id === p.produto_id);
              const m = D2.mtmDe(p.id, D2.HOJE);
              const mtm = m ? m.mtm_valor : 0;
              const v = m ? m.variacao_dia : 0;
              return (
                <tr key={p.id} onClick={() => setSel(p)} style={{ cursor: "pointer" }}>
                  <td className="mono" style={{ color: "var(--fg-3)" }}>{p.id}</td>
                  <td>
                    <div style={{ fontWeight: 500 }}>{prod.nome}</div>
                    <div style={{ fontSize: 11, color: "var(--fg-3)", fontFamily: "var(--mono)" }}>
                      {p.extra.codigo_contrato || p.extra.indexador || (p.extra.tipo_opcao ? `${p.extra.tipo_opcao} K${fmtNum(p.extra.strike, 2)}` : "")}
                    </div>
                  </td>
                  <td><InstrumentoPill tipo={p.instrumento} /></td>
                  <td><LadoPill lado={p.lado} /></td>
                  <td className="num">{p.instrumento === "NDF" ? fmtNum(p.extra.valor_nocional, 0) : fmtNum(p.quantidade, 0)}</td>
                  <td className="mono" style={{ color: "var(--fg-2)", fontSize: 11.5 }}>
                    {fmtDate(p.data_entrada)} → {fmtDate(p.data_vencimento)}
                  </td>
                  <td style={{ fontSize: 12, color: p.contraparte ? "var(--fg-1)" : "var(--fg-3)" }}>
                    {p.contraparte || "—"}
                  </td>
                  <td className="num" style={{ color: mtm >= 0 ? "var(--pos)" : "var(--neg)", fontWeight: 500 }}>
                    {m ? fmtBRL(mtm, { decimals: 0 }) : "—"}
                  </td>
                  <td className="num" style={{ color: v >= 0 ? "var(--pos)" : "var(--neg)" }}>
                    {m ? fmtSignedNum(v, 0) : "—"}
                  </td>
                  <td>
                    {p.status === "ABERTA" && <span className="pill pos">ABERTA</span>}
                    {p.status === "ENCERRADA" && <span className="pill muted">ENCERRADA</span>}
                    {p.status === "VENCIDA" && <span className="pill amber">VENCIDA</span>}
                  </td>
                  <td><Icon name="chevron" size={12} /></td>
                </tr>
              );
            })}
          </tbody>
        </table>

        <div style={{ padding: "10px 16px", display: "flex", justifyContent: "space-between", color: "var(--fg-2)", fontFamily: "var(--mono)", fontSize: 11.5 }}>
          <div>página 1 de 1 · 50 por página</div>
          <div>{filtered.length} registros</div>
        </div>
      </div>

      {sel && <DetalhePosicaoModal pos={sel} onClose={() => setSel(null)} />}
    </div>
  );
}

function DetalhePosicaoModal({ pos, onClose }) {
  const prod = D2.PRODUTOS.find((x) => x.id === pos.produto_id);
  const hist = D2.MTM.filter((m) => m.posicao_id === pos.id);
  const valores = hist.map((m) => m.mtm_valor);
  const labels = hist.map((m) => m.data_calculo.slice(5).replace("-", "/"));
  const ultimo = hist[hist.length - 1];

  const extraRows = [];
  if (pos.instrumento === "FUTURO") {
    extraRows.push(["Preço de entrada", fmtNum(pos.extra.preco_entrada, 4)]);
    extraRows.push(["Código do contrato", pos.extra.codigo_contrato]);
  } else if (pos.instrumento === "NDF") {
    extraRows.push(["Taxa contratada", fmtNum(pos.extra.taxa_contratada, 4)]);
    extraRows.push(["Valor nocional", fmtNum(pos.extra.valor_nocional, 0) + " " + pos.extra.moeda_nocional]);
  } else if (pos.instrumento === "OPCAO") {
    extraRows.push(["Tipo / Estilo", `${pos.extra.tipo_opcao} · ${pos.extra.estilo}`]);
    extraRows.push(["Strike", fmtNum(pos.extra.strike, 4)]);
    extraRows.push(["Prêmio pago", fmtNum(pos.extra.premio_pago, 4)]);
  } else if (pos.instrumento === "OTC") {
    extraRows.push(["Preço de entrada", fmtNum(pos.extra.preco_entrada, 4)]);
    extraRows.push(["Indexador", pos.extra.indexador]);
    extraRows.push(["Prêmio OTC", fmtNum(pos.extra.premio_otc, 4)]);
  }

  return (
    <Modal title={`Posição #${pos.id} · ${prod.nome}`} onClose={onClose} footer={<>
      <button className="btn" onClick={onClose}>Fechar</button>
      <button className="btn danger"><Icon name="x" /> Encerrar</button>
      <button className="btn"><Icon name="edit" /> Editar</button>
    </>}>
      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 18 }}>
        <div>
          <div style={{ display: "flex", gap: 6, marginBottom: 12 }}>
            <InstrumentoPill tipo={pos.instrumento} />
            <LadoPill lado={pos.lado} />
            <span className="pill muted">{pos.mercado}</span>
            <span className={"pill " + (pos.status === "ABERTA" ? "pos" : pos.status === "VENCIDA" ? "amber" : "muted")}>{pos.status}</span>
          </div>
          <div className="kv-grid">
            <div className="k">Produto</div>
            <div className="v" style={{ fontFamily: "var(--sans)" }}>{prod.nome}</div>
            <div className="k">Quantidade</div>
            <div className="v">{fmtNum(pos.quantidade, 0)} {prod.unidade}</div>
            <div className="k">Entrada</div>
            <div className="v">{fmtDate(pos.data_entrada)}</div>
            <div className="k">Vencimento</div>
            <div className="v">{fmtDate(pos.data_vencimento)}</div>
            <div className="k">Contraparte</div>
            <div className="v" style={{ fontFamily: "var(--sans)", color: pos.contraparte ? "var(--fg-0)" : "var(--fg-3)" }}>{pos.contraparte || "—"}</div>
            <div className="k">Criado por</div>
            <div className="v">{pos.criado_por}</div>
            {extraRows.map(([k, v]) => (
              <React.Fragment key={k}>
                <div className="k" style={{ color: "var(--amber-dim)" }}>{k}</div>
                <div className="v">{v}</div>
              </React.Fragment>
            ))}
          </div>
          {pos.observacoes && (
            <div style={{ marginTop: 14, fontSize: 12, color: "var(--fg-1)", lineHeight: 1.6, padding: 10, background: "var(--bg-2)", borderRadius: 3 }}>
              <div style={{ fontSize: 10.5, color: "var(--fg-3)", textTransform: "uppercase", letterSpacing: "0.08em", marginBottom: 4 }}>Observações</div>
              {pos.observacoes}
            </div>
          )}
        </div>
        <div>
          <div style={{ fontSize: 11, color: "var(--fg-3)", textTransform: "uppercase", letterSpacing: "0.08em", fontWeight: 600, marginBottom: 8 }}>
            MtM atual
          </div>
          <div style={{ fontFamily: "var(--mono)", fontSize: 28, fontWeight: 500, color: (ultimo?.mtm_valor || 0) >= 0 ? "var(--pos)" : "var(--neg)" }}>
            {fmtBRL(ultimo?.mtm_valor || 0)}
          </div>
          <div className="num" style={{ color: (ultimo?.variacao_dia || 0) >= 0 ? "var(--pos)" : "var(--neg)", marginTop: 4, fontSize: 12 }}>
            {fmtSignedNum(ultimo?.variacao_dia || 0, 0)} BRL · variação D−1
          </div>

          <div style={{ marginTop: 18 }}>
            <div style={{ fontSize: 11, color: "var(--fg-3)", textTransform: "uppercase", letterSpacing: "0.08em", fontWeight: 600, marginBottom: 8 }}>
              Histórico de MtM
            </div>
            <LineChart series={[{ values: valores, labels, color: "var(--amber)", fill: true }]} height={140} width={400} />
          </div>

          <table className="t dense" style={{ marginTop: 14 }}>
            <thead><tr>
              <th>Data</th><th className="num">Preço</th><th className="num">MtM</th><th className="num">Δ</th>
            </tr></thead>
            <tbody>
              {hist.slice(-5).reverse().map((m) => (
                <tr key={m.data_calculo}>
                  <td className="mono">{fmtDate(m.data_calculo)}</td>
                  <td className="num">{fmtNum(m.preco_mercado, 4)}</td>
                  <td className="num" style={{ color: m.mtm_valor >= 0 ? "var(--pos)" : "var(--neg)" }}>{fmtBRL(m.mtm_valor, { decimals: 0 })}</td>
                  <td className="num" style={{ color: m.variacao_dia >= 0 ? "var(--pos)" : "var(--neg)" }}>{fmtSignedNum(m.variacao_dia, 0)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </Modal>
  );
}

Object.assign(window, { NovaPosicaoScreen, PosicoesScreen });
