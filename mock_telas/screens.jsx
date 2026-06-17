/* global React, window */
// Todas as telas do MVP.

const { useState, useMemo, useEffect, useRef } = React;
const D = window.__APP_DATA__;

// ============================================================
// LOGIN
// ============================================================
function LoginScreen({ onLogin }) {
  const [user, setUser] = useState("m.duarte");
  const [pwd, setPwd] = useState("••••••••••");
  const [loading, setLoading] = useState(false);

  const submit = (e) => {
    e.preventDefault();
    setLoading(true);
    setTimeout(() => {setLoading(false);onLogin();}, 600);
  };

  return (
    <div className="login-shell" data-screen-label="01 Login">
      <div className="login-art">
        <div className="grid"></div>
        <div style={{ display: "flex", alignItems: "center", gap: 10, position: "relative" }}>
          <span className="brand-mark"></span>
          <span style={{ fontWeight: 600, letterSpacing: "-0.005em" }}>NeverVenture</span>
          <span style={{ color: "var(--fg-3)", fontFamily: "var(--mono)", fontSize: 11, marginLeft: 8 }}>
            risk.commodities/mvp
          </span>
        </div>
        <div style={{ marginTop: "auto", position: "relative", maxWidth: 520 }}>
          <div className="crumb" style={{ marginBottom: 14 }}>Mark-to-market · Posição · P&L</div>
          <div style={{ fontSize: 36, lineHeight: 1.15, fontWeight: 500, letterSpacing: "-0.022em", color: "var(--fg-0)" }}>
            Marcação a mercado diária<br />
            para mesa de risco de <span style={{ color: "var(--amber)" }}>commodities</span>.
          </div>
          <div style={{ marginTop: 18, color: "var(--fg-2)", fontSize: 13.5, maxWidth: 460, lineHeight: 1.6 }}>
            Posições, preços e motor polimórfico de MtM em um único terminal.
            Lance o pregão, dispare o cálculo, audite a exposição da mesa em segundos.
          </div>
          <div style={{ display: "flex", gap: 22, marginTop: 36, fontFamily: "var(--mono)", fontSize: 11, color: "var(--fg-3)" }}>
            <div><span style={{ color: "var(--amber)" }}>FUTURO</span> · NDF · OPÇÃO · OTC</div>
            <div>v1.0 · 2026-05-25</div>
          </div>
        </div>
      </div>

      <form className="login-form" onSubmit={submit}>
        <div className="crumb">Acesso restrito</div>
        <h1 style={{ fontSize: 24, fontWeight: 500, margin: "6px 0 28px", letterSpacing: "-0.012em" }}>
          Entrar no terminal
        </h1>

        <div style={{ display: "flex", flexDirection: "column", gap: 14 }}>
          <div className="field">
            <label>Login</label>
            <input type="text" className="mono" value={user} onChange={(e) => setUser(e.target.value)} autoFocus />
          </div>
          <div className="field">
            <label>Senha</label>
            <input type="password" className="mono" value={pwd} onChange={(e) => setPwd(e.target.value)} />
          </div>
          <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", marginTop: 4 }}>
            <label style={{ display: "flex", alignItems: "center", gap: 7, color: "var(--fg-2)", fontSize: 12 }}>
              <input type="checkbox" defaultChecked style={{ width: "auto" }} />
              Manter conectado por 8h
            </label>
            <a href="#" style={{ color: "var(--amber)", fontSize: 12, textDecoration: "none" }}>Esqueci a senha</a>
          </div>

          <button className="btn primary" type="submit" disabled={loading} style={{ marginTop: 12, justifyContent: "center", padding: "10px" }}>
            {loading ? <><Icon name="refresh" /> Autenticando…</> : <>Entrar &nbsp;<Icon name="chevron" /></>}
          </button>
        </div>

        <div style={{ marginTop: 28, padding: "12px 14px", background: "var(--bg-2)", borderRadius: 4, fontSize: 11, color: "var(--fg-2)", fontFamily: "var(--mono)", lineHeight: 1.6 }}>
          <div><span style={{ color: "var(--fg-3)" }}>auth</span>  JWT · 1h access · 8h refresh</div>
          <div><span style={{ color: "var(--fg-3)" }}>perfil</span> OPERADOR · GESTOR · ADMIN</div>
        </div>

        <div style={{ marginTop: "auto", color: "var(--fg-3)", fontSize: 11, fontFamily: "var(--mono)", paddingTop: 36 }}>
          © 2026 NeverVenture · MVP risco mercado v1.0
        </div>
      </form>
    </div>);

}

// ============================================================
// DASHBOARD
// ============================================================
function DashboardScreen({ goto }) {
  const hoje = D.HOJE;
  const ontem = D.DATAS_PREGAO[D.DATAS_PREGAO.length - 2];
  const posicoesAbertas = D.POSICOES.filter((p) => p.status === "ABERTA");

  const mtmHoje = D.MTM.filter((m) => m.data_calculo === hoje);
  const totalPL = mtmHoje.reduce((s, m) => s + m.mtm_valor, 0);
  const totalVar = mtmHoje.reduce((s, m) => s + m.variacao_dia, 0);

  const plPorDia = D.DATAS_PREGAO.map((d) =>
  D.MTM.filter((m) => m.data_calculo === d).reduce((s, m) => s + m.mtm_valor, 0)
  );

  const ultExec = D.EXECUCOES[D.EXECUCOES.length - 1];

  const exposicaoPorProduto = useMemo(() => {
    const map = {};
    for (const p of posicoesAbertas) {
      const k = p.produto_id;
      map[k] = map[k] || { produto_id: k, comprado: 0, vendido: 0 };
      const q = p.instrumento === "NDF" ? p.extra.valor_nocional : p.quantidade;
      if (p.lado === "COMPRADO") map[k].comprado += q;else map[k].vendido += q;
    }
    return Object.values(map);
  }, [posicoesAbertas]);

  const topMovers = useMemo(() => {
    return [...mtmHoje].
    sort((a, b) => Math.abs(b.variacao_dia) - Math.abs(a.variacao_dia)).
    slice(0, 5).
    map((m) => {
      const pos = D.POSICOES.find((p) => p.id === m.posicao_id);
      const prod = D.PRODUTOS.find((p) => p.id === pos.produto_id);
      return { ...m, pos, prod };
    });
  }, [mtmHoje]);

  return (
    <div className="page" data-screen-label="02 Dashboard">
      <div className="page-head">
        <div>
          <div className="crumb">Mesa de risco · {fmtDateLong(hoje)}</div>
          <h1>Bom pregão, Marina.</h1>
          <div className="sub">Snapshot do dia · {posicoesAbertas.length} posições abertas em 6 produtos · última execução {ultExec.iniciado_em.split(" ")[1]}</div>
        </div>
        <div style={{ display: "flex", gap: 8 }}>
          <button className="btn" onClick={() => goto("precos")}>
            <Icon name="upload" /> Lançar preços
          </button>
          <button className="btn primary" onClick={() => goto("motor")}>
            <Icon name="play" /> Disparar motor
          </button>
        </div>
      </div>

      {/* Stats row */}
      <div style={{ display: "grid", gridTemplateColumns: "repeat(4, 1fr)", gap: 14, marginBottom: 22 }}>
        <div className="stat">
          <div className="k">P&L acumulado · mesa</div>
          <div className="v" style={{ color: totalPL >= 0 ? "var(--pos)" : "var(--neg)" }}>
            {fmtBRL(totalPL, { decimals: 0 })}
          </div>
          <div className="delta" style={{ color: totalVar >= 0 ? "var(--pos)" : "var(--neg)" }}>
            {fmtSignedNum(totalVar, 0)} BRL  ·  variação D−1
          </div>
        </div>
        <div className="stat">
          <div className="k">Posições abertas</div>
          <div className="v">{posicoesAbertas.length}</div>
          <div className="delta" style={{ color: "var(--fg-2)" }}>
            6 FUT · 2 NDF · 3 OPÇ · 2 OTC
          </div>
        </div>
        <div className="stat">
          <div className="k">Produtos com exposição</div>
          <div className="v">6</div>
          <div className="delta" style={{ color: "var(--fg-2)" }}>
            Soja · Milho · Café · Açúcar · Boi · Etanol
          </div>
        </div>
        <div className="stat">
          <div className="k">Motor MtM</div>
          <div className="v" style={{ fontSize: 18, color: "var(--pos)", display: "flex", alignItems: "center", gap: 8 }}>
            <span className="pulse"></span> OK
          </div>
          <div className="delta" style={{ color: "var(--fg-2)" }}>
            {ultExec.sucessos}/{ultExec.posicoes_processadas} · {(ultExec.duracao_ms / 1000).toFixed(1)}s · {ultExec.usuario}
          </div>
        </div>
      </div>

      {/* Main row */}
      <div style={{ display: "grid", gridTemplateColumns: "2fr 1fr", gap: 14, marginBottom: 14 }}>
        <div className="panel">
          <div className="panel-head">
            <h3>P&L acumulado da mesa — últimos 12 pregões</h3>
            <div className="meta">BRL · soma de mtm_valor</div>
          </div>
          <div style={{ padding: "12px 16px 18px" }}>
            <LineChart series={[{
              values: plPorDia,
              labels: D.DATAS_PREGAO.map((d) => d.slice(5).replace("-", "/")),
              color: "var(--amber)",
              fill: true
            }]} height={260} />
          </div>
        </div>

        <div className="panel">
          <div className="panel-head">
            <h3>Movers do dia</h3>
            <div className="meta">|Δ|  ·  D−1</div>
          </div>
          <div>
            {topMovers.map((m) =>
            <div key={m.posicao_id} style={{
              display: "flex", alignItems: "center", justifyContent: "space-between",
              padding: "10px 16px", borderBottom: "1px solid var(--line-0)"
            }}>
                <div style={{ display: "flex", alignItems: "center", gap: 10, minWidth: 0 }}>
                  <span style={{ fontFamily: "var(--mono)", fontSize: 11, color: "var(--fg-3)" }}>#{m.posicao_id}</span>
                  <div style={{ minWidth: 0 }}>
                    <div style={{ fontWeight: 500, fontSize: 12.5, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>
                      {m.prod.nome}
                    </div>
                    <div style={{ display: "flex", gap: 6, marginTop: 2 }}>
                      <InstrumentoPill tipo={m.pos.instrumento} />
                      <LadoPill lado={m.pos.lado} />
                    </div>
                  </div>
                </div>
                <div className="num" style={{ color: m.variacao_dia >= 0 ? "var(--pos)" : "var(--neg)", fontSize: 12.5, fontWeight: 500 }}>
                  {fmtBRL(m.variacao_dia, { sign: true, decimals: 0 })}
                </div>
              </div>
            )}
          </div>
        </div>
      </div>

      {/* Exposição overview + execucoes */}
      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 14 }}>
        <div className="panel">
          <div className="panel-head">
            <h3>Exposição líquida por produto</h3>
            <button className="btn xs ghost" onClick={() => goto("rel-exposicao")}>
              ver detalhe <Icon name="chevron" size={11} />
            </button>
          </div>
          <table className="t dense">
            <thead><tr>
              <th>Produto</th><th className="num">Comprado</th><th className="num">Vendido</th><th className="num">Líquido</th>
            </tr></thead>
            <tbody>
              {exposicaoPorProduto.map((e) => {
                const p = D.PRODUTOS.find((p) => p.id === e.produto_id);
                const liq = e.comprado - e.vendido;
                return (
                  <tr key={e.produto_id}>
                    <td>{p.nome} <span style={{ color: "var(--fg-3)", fontFamily: "var(--mono)", fontSize: 11 }}>· {p.unidade}</span></td>
                    <td className="num">{fmtNum(e.comprado, 0)}</td>
                    <td className="num">{fmtNum(e.vendido, 0)}</td>
                    <td className="num" style={{ color: liq >= 0 ? "var(--pos)" : "var(--neg)", fontWeight: 500 }}>
                      {fmtSignedNum(liq, 0)}
                    </td>
                  </tr>);

              })}
            </tbody>
          </table>
        </div>

        <div className="panel">
          <div className="panel-head">
            <h3>Últimas execuções do motor</h3>
            <button className="btn xs ghost" onClick={() => goto("motor")}>
              ver detalhe <Icon name="chevron" size={11} />
            </button>
          </div>
          <table className="t dense">
            <thead><tr>
              <th>Data</th><th>Disparado</th><th>Por</th><th className="num">Duração</th><th>Resultado</th>
            </tr></thead>
            <tbody>
              {D.EXECUCOES.slice().reverse().slice(0, 6).map((ex) =>
              <tr key={ex.id}>
                  <td className="mono">{fmtDate(ex.data_calculo)}</td>
                  <td className="mono" style={{ color: "var(--fg-2)" }}>{ex.iniciado_em.split(" ")[1]}</td>
                  <td className="mono" style={{ color: "var(--fg-2)" }}>{ex.usuario}</td>
                  <td className="num">{(ex.duracao_ms / 1000).toFixed(1)}s</td>
                  <td>
                    {ex.falhas.length === 0 ?
                  <span className="pill pos">{ex.sucessos}/{ex.posicoes_processadas} OK</span> :
                  <span className="pill neg">{ex.sucessos}/{ex.posicoes_processadas} · {ex.falhas.length} falha</span>}
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>);

}

// ============================================================
// PRODUTOS
// ============================================================
function ProdutosScreen() {
  const [filter, setFilter] = useState("todos");
  const [openNew, setOpenNew] = useState(false);
  const produtos = D.PRODUTOS.filter((p) =>
  filter === "todos" ? true : filter === "ativos" ? p.ativo : !p.ativo
  );

  return (
    <div className="page" data-screen-label="03 Produtos">
      <div className="page-head">
        <div>
          <div className="crumb">Cadastros · Produtos</div>
          <h1>Produtos &amp; commodities</h1>
          <div className="sub">{D.PRODUTOS.length} produtos cadastrados · {D.PRODUTOS.filter((p) => p.ativo).length} ativos</div>
        </div>
        <div style={{ display: "flex", gap: 8 }}>
          <button className="btn"><Icon name="download" /> Exportar</button>
          <button className="btn primary" onClick={() => setOpenNew(true)}>
            <Icon name="plus" /> Novo produto
          </button>
        </div>
      </div>

      <div className="panel">
        <div className="panel-head" style={{ gap: 12 }}>
          <div style={{ display: "flex", alignItems: "center", gap: 12 }}>
            <div style={{ position: "relative", width: 280 }}>
              <input type="text" placeholder="Buscar por nome, bolsa, código…" style={{ paddingLeft: 30 }} />
              <span style={{ position: "absolute", left: 9, top: 8, color: "var(--fg-3)" }}><Icon name="search" /></span>
            </div>
            <div className="seg">
              <button className={filter === "todos" ? "on" : ""} onClick={() => setFilter("todos")}>TODOS</button>
              <button className={filter === "ativos" ? "on" : ""} onClick={() => setFilter("ativos")}>ATIVOS</button>
              <button className={filter === "inativos" ? "on" : ""} onClick={() => setFilter("inativos")}>INATIVOS</button>
            </div>
          </div>
          <div className="meta">7 registros · ordenado por nome</div>
        </div>

        <table className="t">
          <thead><tr>
            <th style={{ width: 50 }}>ID</th>
            <th>Nome</th>
            <th>Unidade</th>
            <th>Bolsa</th>
            <th>Moeda</th>
            <th className="num">Contratos em aberto</th>
            <th>Status</th>
            <th style={{ width: 90 }}></th>
          </tr></thead>
          <tbody>
            {produtos.map((p) =>
            <tr key={p.id}>
                <td className="mono" style={{ color: "var(--fg-3)" }}>#{String(p.id).padStart(3, "0")}</td>
                <td style={{ fontWeight: 500 }}>{p.nome}</td>
                <td className="mono" style={{ color: "var(--fg-2)" }}>{p.unidade}</td>
                <td><span className="pill muted">{p.bolsa_ref}</span></td>
                <td className="mono">{p.moeda_cotacao}</td>
                <td className="num">{p.ativo ? fmtNum(p.contratos, 0) : "—"}</td>
                <td>
                  {p.ativo ?
                <span className="pill pos"><span style={{ width: 5, height: 5, borderRadius: "50%", background: "currentColor", display: "inline-block" }} />ATIVO</span> :
                <span className="pill muted">INATIVO</span>}
                </td>
                <td style={{ textAlign: "right" }}>
                  <button className="btn xs ghost"><Icon name="edit" /></button>
                  <button className="btn xs ghost"><Icon name="trash" /></button>
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      {openNew &&
      <Modal title="Novo produto" onClose={() => setOpenNew(false)} footer={<>
          <button className="btn" onClick={() => setOpenNew(false)}>Cancelar</button>
          <button className="btn primary" onClick={() => setOpenNew(false)}><Icon name="check" /> Salvar produto</button>
        </>}>
          <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 14 }}>
            <div className="field" style={{ gridColumn: "1 / 3" }}>
              <label>Nome do produto<span className="req">*</span></label>
              <input type="text" placeholder="ex.: Trigo CBOT" />
            </div>
            <div className="field">
              <label>Unidade de cotação<span className="req">*</span></label>
              <select><option>bushel</option><option>saca 60kg</option><option>tonelada</option><option>arroba</option><option>barril</option></select>
            </div>
            <div className="field">
              <label>Bolsa de referência<span className="req">*</span></label>
              <select><option>CBOT</option><option>B3</option><option>ICE</option><option>NYMEX</option></select>
            </div>
            <div className="field">
              <label>Moeda de cotação<span className="req">*</span></label>
              <select><option>USD</option><option>BRL</option><option>EUR</option></select>
            </div>
            <div className="field">
              <label>Status</label>
              <select><option>ATIVO</option><option>INATIVO</option></select>
            </div>
          </div>
          <div className="doc-strip" style={{ marginTop: 18 }}>
            <b>RN-006</b> OTC com indexador deve referenciar produto cadastrado.
          </div>
        </Modal>
      }
    </div>);

}

// ============================================================
// Autocomplete de produto (usado em listas com 40+ registros)
// ============================================================
function ProdutoAutocomplete({ value, onChange, produtos, anchor }) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const [highlight, setHighlight] = useState(0);
  const wrapRef = useRef(null);
  const inputRef = useRef(null);

  const selected = produtos.find((p) => p.id === value);

  useEffect(() => {
    const onDocClick = (e) => {
      if (wrapRef.current && !wrapRef.current.contains(e.target)) setOpen(false);
    };
    document.addEventListener("mousedown", onDocClick);
    return () => document.removeEventListener("mousedown", onDocClick);
  }, []);

  const q = query.trim().toLowerCase();
  const filtered = produtos.filter((p) => {
    if (!q) return true;
    return (
      p.nome.toLowerCase().includes(q) ||
      p.bolsa_ref.toLowerCase().includes(q) ||
      p.moeda_cotacao.toLowerCase().includes(q) ||
      p.unidade.toLowerCase().includes(q) ||
      String(p.id).padStart(3, "0").includes(q)
    );
  });

  const select = (p) => {
    onChange(p.id);
    setQuery("");
    setOpen(false);
    inputRef.current?.blur();
  };

  const onKey = (e) => {
    if (e.key === "ArrowDown") { e.preventDefault(); setOpen(true); setHighlight((h) => Math.min(h + 1, filtered.length - 1)); }
    else if (e.key === "ArrowUp") { e.preventDefault(); setHighlight((h) => Math.max(h - 1, 0)); }
    else if (e.key === "Enter") { e.preventDefault(); if (filtered[highlight]) select(filtered[highlight]); }
    else if (e.key === "Escape") { setOpen(false); inputRef.current?.blur(); }
  };

  const display = open
    ? query
    : (selected ? `${selected.nome} · ${selected.bolsa_ref} · ${selected.moeda_cotacao}/${selected.unidade}` : "");

  const renderName = (name) => {
    if (!q) return name;
    const i = name.toLowerCase().indexOf(q);
    if (i < 0) return name;
    return (
      <>
        {name.slice(0, i)}
        <mark style={{ background: "transparent", color: "var(--amber)", padding: 0, fontWeight: 600 }}>
          {name.slice(i, i + q.length)}
        </mark>
        {name.slice(i + q.length)}
      </>
    );
  };

  return (
    <div ref={wrapRef} style={{ position: "relative" }} data-comment-anchor={anchor}>
      <input
        ref={inputRef}
        type="text"
        value={display}
        onChange={(e) => { setQuery(e.target.value); setOpen(true); setHighlight(0); }}
        onFocus={() => { setOpen(true); setQuery(""); setHighlight(0); }}
        onKeyDown={onKey}
        placeholder="Buscar produto por nome, bolsa, moeda…"
        style={{ paddingRight: 30 }}
        autoComplete="off"
      />
      <span style={{ position: "absolute", right: 9, top: 9, color: "var(--fg-3)", pointerEvents: "none" }}>
        <Icon name={open ? "search" : "chevron"} size={12} />
      </span>
      {open && (
        <div style={{
          position: "absolute", top: "calc(100% + 4px)", left: 0, right: 0,
          background: "var(--bg-2)", border: "1px solid var(--line-1)",
          borderRadius: 4, maxHeight: 300, overflow: "auto", zIndex: 30,
          boxShadow: "0 16px 40px oklch(0 0 0 / 0.45)",
        }}>
          {filtered.length === 0 ? (
            <div style={{ padding: 14, color: "var(--fg-3)", fontSize: 12, fontFamily: "var(--mono)" }}>
              Nenhum produto corresponde a “{query}”
            </div>
          ) : filtered.map((p, i) => (
            <div
              key={p.id}
              onMouseDown={(e) => { e.preventDefault(); select(p); }}
              onMouseEnter={() => setHighlight(i)}
              style={{
                padding: "8px 12px",
                cursor: "pointer",
                background: i === highlight ? "var(--bg-3)" : "transparent",
                borderLeft: "2px solid " + (p.id === value ? "var(--amber)" : "transparent"),
                display: "flex", justifyContent: "space-between", alignItems: "center", gap: 12,
              }}
            >
              <div style={{ minWidth: 0 }}>
                <div style={{ fontSize: 12.5, fontWeight: 500 }}>{renderName(p.nome)}</div>
                <div style={{ fontSize: 11, color: "var(--fg-3)", fontFamily: "var(--mono)", marginTop: 2 }}>
                  {p.bolsa_ref} · {p.moeda_cotacao}/{p.unidade}
                </div>
              </div>
              <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
                {!p.ativo && <span className="pill muted" style={{ fontSize: 10 }}>INATIVO</span>}
                <span className="mono" style={{ fontSize: 11, color: "var(--fg-3)" }}>#{String(p.id).padStart(3, "0")}</span>
              </div>
            </div>
          ))}
          <div style={{
            padding: "6px 12px", borderTop: "1px solid var(--line-0)",
            fontSize: 10.5, color: "var(--fg-3)", fontFamily: "var(--mono)",
            display: "flex", gap: 14, justifyContent: "space-between",
            background: "var(--bg-1)", position: "sticky", bottom: 0,
          }}>
            <div>↑↓ navegar · ↵ selecionar · esc fechar</div>
            <div>{filtered.length} de {produtos.length}</div>
          </div>
        </div>
      )}
    </div>
  );
}

// ============================================================
// PREÇOS — formulário + upload CSV
// ============================================================
function PrecosScreen() {
  const [tab, setTab] = useState("manual");
  const [produtoSel, setProdutoSel] = useState(1);
  const [data, setData] = useState(D.HOJE);
  const [preco, setPreco] = useState("");
  const [cambio, setCambio] = useState("5.1240");

  const ultimos = D.PRECOS.
  filter((p) => p.produto_id === produtoSel).
  slice(-8).
  reverse();

  const csvSimRows = [
  { ok: true, produto: "Soja CBOT", data: "2026-05-25", preco: "1418.7600", cambio: "5.1240" },
  { ok: true, produto: "Milho B3", data: "2026-05-25", preco: "  71.4200", cambio: "5.1240" },
  { ok: true, produto: "Café Arábica", data: "2026-05-25", preco: " 219.1500", cambio: "5.1240" },
  { ok: false, produto: "Açúcar #11", data: "2026-05-25", preco: "      —", cambio: "5.1240", erro: "preço ausente" },
  { ok: true, produto: "Boi Gordo", data: "2026-05-25", preco: " 312.8000", cambio: "5.1240" },
  { ok: true, produto: "Etanol Hidratado", data: "2026-05-25", preco: "2945.0000", cambio: "5.1240" },
  { ok: false, produto: "Trigo CBOT", data: "2026-05-25", preco: " 685.0000", cambio: "5.1240", erro: "produto_id não cadastrado" }];


  return (
    <div className="page" data-screen-label="04 Preços">
      <div className="page-head">
        <div>
          <div className="crumb">Cadastros · Preços de referência</div>
          <h1>Lançamento de preços do pregão</h1>
          <div className="sub">D = {D.HOJE} · uma cotação por produto por dia (constraint UNIQUE)</div>
        </div>
        <div className="seg">
          <button className={tab === "manual" ? "on" : ""} onClick={() => setTab("manual")}>MANUAL</button>
          <button className={tab === "csv" ? "on" : ""} onClick={() => setTab("csv")}>UPLOAD CSV</button>
        </div>
      </div>

      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 14 }}>
        {tab === "manual" ?
        <div className="panel">
            <div className="panel-head"><h3>Cotação manual</h3><div className="meta">POST /precos</div></div>
            <div className="panel-body" style={{ display: "grid", gap: 14 }}>
              <div className="field">
                <label>Produto<span className="req">*</span></label>
                <ProdutoAutocomplete
                  value={produtoSel}
                  onChange={setProdutoSel}
                  produtos={D.PRODUTOS.filter((p) => p.ativo)}
                  anchor="f188592f4e-select-457-17"
                />
                <div className="help">Digite para filtrar entre os {D.PRODUTOS.filter((p) => p.ativo).length}+ produtos ativos · navegação por teclado</div>
              </div>
              <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 14 }}>
                <div className="field">
                  <label>Data do pregão<span className="req">*</span></label>
                  <input type="date" value={data} onChange={(e) => setData(e.target.value)} />
                </div>
                <div className="field">
                  <label>Câmbio BRL<span className="req">*</span></label>
                  <input className="mono" value={cambio} onChange={(e) => setCambio(e.target.value)} />
                </div>
              </div>
              <div className="field">
                <label>Preço de fechamento<span className="req">*</span></label>
                <div style={{ display: "flex", gap: 8 }}>
                  <input className="mono" placeholder="0,000000" value={preco} onChange={(e) => setPreco(e.target.value)} />
                  <div style={{ background: "var(--bg-2)", border: "1px solid var(--line-1)", padding: "7px 12px", borderRadius: 3, fontFamily: "var(--mono)", fontSize: 12, color: "var(--fg-2)", whiteSpace: "nowrap" }}>
                    {D.PRODUTOS.find((p) => p.id === produtoSel)?.moeda_cotacao} / {D.PRODUTOS.find((p) => p.id === produtoSel)?.unidade}
                  </div>
                </div>
                <div className="help">RN-008: preço &gt; 0. RN-009: câmbio &gt; 0.</div>
              </div>

              <div className="hr"></div>

              <div style={{ fontSize: 11, color: "var(--fg-3)", textTransform: "uppercase", letterSpacing: "0.08em", fontWeight: 600 }}>
                Campos reservados (não usados no MVP)
              </div>
              <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 14 }}>
                <div className="field">
                  <label>Volatilidade implícita</label>
                  <input className="mono" placeholder="—" disabled />
                </div>
                <div className="field">
                  <label>Taxa de juros</label>
                  <input className="mono" placeholder="—" disabled />
                </div>
              </div>

              <div style={{ display: "flex", justifyContent: "flex-end", gap: 8, marginTop: 8 }}>
                <button className="btn">Cancelar</button>
                <button className="btn primary"><Icon name="check" /> Salvar cotação</button>
              </div>
            </div>
          </div> :

        <div className="panel">
            <div className="panel-head"><h3>Upload CSV</h3><div className="meta">POST /precos/upload</div></div>
            <div className="panel-body" style={{ display: "grid", gap: 14 }}>
              <div className="dropzone">
                <div style={{ fontSize: 22, color: "var(--amber)" }}><Icon name="upload" size={28} stroke={1.2} /></div>
                <div style={{ marginTop: 8 }}><strong>Arraste o CSV ou clique para selecionar</strong></div>
                <div style={{ marginTop: 4 }}>colunas: produto_id, data_preco, preco_fechamento, cambio_brl</div>
              </div>

              <div style={{ background: "var(--bg-2)", border: "1px solid var(--line-0)", borderRadius: 4, padding: 12, fontFamily: "var(--mono)", fontSize: 11.5, color: "var(--fg-1)", lineHeight: 1.7 }}>
                <div style={{ color: "var(--fg-3)" }}># fechamento_25-05-2026.csv</div>
                <div>produto_id,data_preco,preco_fechamento,cambio_brl</div>
                <div>1,2026-05-25,1418.76,5.12</div>
                <div>2,2026-05-25,71.42,5.12</div>
                <div>3,2026-05-25,219.15,5.12</div>
              </div>

              <div className="doc-strip">
                <b>RN-010</b> Linhas com erro não bloqueiam linhas válidas. O sistema retorna relatório de aceitas e rejeitadas.
              </div>

              <div>
                <div style={{ fontSize: 11, color: "var(--fg-3)", textTransform: "uppercase", letterSpacing: "0.08em", fontWeight: 600, marginBottom: 8 }}>
                  Pré-visualização · 7 linhas
                </div>
                <table className="t dense">
                  <thead><tr><th></th><th>Produto</th><th className="num">Preço</th><th className="num">Câmbio</th><th>Status</th></tr></thead>
                  <tbody>
                    {csvSimRows.map((r, i) =>
                  <tr key={i}>
                        <td style={{ width: 24, color: r.ok ? "var(--pos)" : "var(--neg)" }}>
                          <Icon name={r.ok ? "check" : "x"} size={13} />
                        </td>
                        <td style={{ fontSize: 12 }}>{r.produto}</td>
                        <td className="num">{r.preco}</td>
                        <td className="num">{r.cambio}</td>
                        <td style={{ fontSize: 11, color: r.ok ? "var(--fg-2)" : "var(--neg)", fontFamily: "var(--mono)" }}>
                          {r.ok ? "aceita" : r.erro}
                        </td>
                      </tr>
                  )}
                  </tbody>
                </table>
              </div>

              <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginTop: 4 }}>
                <div style={{ fontSize: 12, color: "var(--fg-2)", fontFamily: "var(--mono)" }}>
                  <span className="pos-text">5 aceitas</span> · <span className="neg-text">2 rejeitadas</span>
                </div>
                <div style={{ display: "flex", gap: 8 }}>
                  <button className="btn">Cancelar</button>
                  <button className="btn primary"><Icon name="upload" /> Importar 5 linhas válidas</button>
                </div>
              </div>
            </div>
          </div>
        }

        <div className="panel">
          <div className="panel-head">
            <h3>Últimos pregões — {D.PRODUTOS.find((p) => p.id === produtoSel)?.nome}</h3>
            <div className="meta">{D.PRODUTOS.find((p) => p.id === produtoSel)?.moeda_cotacao}/{D.PRODUTOS.find((p) => p.id === produtoSel)?.unidade}</div>
          </div>
          <table className="t dense">
            <thead><tr>
              <th>Data</th>
              <th className="num">Fechamento</th>
              <th className="num">Δ</th>
              <th className="num">Câmbio BRL</th>
              <th>Auditoria</th>
            </tr></thead>
            <tbody>
              {ultimos.map((p, i) => {
                const ant = ultimos[i + 1];
                const delta = ant ? p.preco_fechamento - ant.preco_fechamento : 0;
                return (
                  <tr key={p.id} className={i === 0 ? "flash" : ""}>
                    <td className="mono">{fmtDate(p.data_preco)}</td>
                    <td className="num" style={{ fontWeight: i === 0 ? 600 : 400 }}>{fmtNum(p.preco_fechamento, 4)}</td>
                    <td className="num" style={{ color: delta >= 0 ? "var(--pos)" : "var(--neg)" }}>
                      {ant ? fmtSignedNum(delta, 4) : "—"}
                    </td>
                    <td className="num" style={{ color: "var(--fg-2)" }}>{fmtNum(p.cambio_brl, 4)}</td>
                    <td className="mono" style={{ color: "var(--fg-3)", fontSize: 11 }}>{p.criado_em.split(" ")[1]}</td>
                  </tr>);

              })}
            </tbody>
          </table>
        </div>
      </div>
    </div>);

}

Object.assign(window, { LoginScreen, DashboardScreen, ProdutosScreen, PrecosScreen });