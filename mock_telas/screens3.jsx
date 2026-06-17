/* global React, window */
const { useState, useMemo, useEffect } = React;
const D3 = window.__APP_DATA__;

// ============================================================
// MOTOR MtM
// ============================================================
function MotorScreen() {
  const [dataCalc, setDataCalc] = useState(D3.HOJE);
  const [running, setRunning] = useState(false);
  const [progress, setProgress] = useState(0);
  const [logLines, setLogLines] = useState([]);
  const [resultado, setResultado] = useState(null);
  const [exSel, setExSel] = useState(D3.EXECUCOES[D3.EXECUCOES.length - 1]);

  const posicoes = D3.POSICOES.filter((p) => p.status === "ABERTA");

  const disparar = () => {
    setRunning(true);
    setProgress(0);
    setLogLines([]);
    setResultado(null);

    const steps = [
      { t: 50,   line: `[18:34:02.114] motor.iniciar  data_calculo=${dataCalc} usuario=${D3.USUARIO.login}` },
      { t: 350,  line: `[18:34:02.118] repo.buscar_abertas  →  ${posicoes.length} posições` },
      { t: 700,  line: `[18:34:02.305] precos.buscar  →  6 produtos · ok` },
    ];
    let p = 0;
    posicoes.forEach((pos, i) => {
      steps.push({
        t: 1000 + i * 380,
        line: `[18:34:0${2 + Math.floor(i / 3)}.${500 + i * 30}] pos#${pos.id} ${pos.instrumento.padEnd(6)} calc_mtm  →  ok`,
        progress: ((i + 1) / posicoes.length) * 100,
      });
    });
    steps.push({ t: 1000 + posicoes.length * 380 + 200, line: `[18:34:09.124] motor.finalizado  sucessos=${posicoes.length} falhas=0  duracao=7.01s`,
      done: true });

    steps.forEach(({ t, line, progress, done }) => {
      setTimeout(() => {
        setLogLines((arr) => [...arr, line]);
        if (progress !== undefined) setProgress(progress);
        if (done) {
          setRunning(false);
          setProgress(100);
          setResultado({
            execucao_id: 48,
            data_calculo: dataCalc,
            posicoes_processadas: posicoes.length,
            sucessos: posicoes.length,
            falhas: [],
            duracao_ms: 7012,
          });
        }
      }, t);
    });
  };

  return (
    <div className="page" data-screen-label="07 Motor MtM">
      <div className="page-head">
        <div>
          <div className="crumb">Processamento · Motor MtM</div>
          <h1>Disparar processamento do pregão</h1>
          <div className="sub">Motor polimórfico · idempotente por (posicao_id, data_calculo)</div>
        </div>
      </div>

      <div style={{ display: "grid", gridTemplateColumns: "1fr 1.2fr", gap: 14, marginBottom: 14 }}>
        {/* Dispatcher */}
        <div className="panel">
          <div className="panel-head">
            <h3>Configuração da execução</h3>
            <div className="meta">POST /motor/processar</div>
          </div>
          <div className="panel-body">
            <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 14 }}>
              <div className="field">
                <label>Data do cálculo<span className="req">*</span></label>
                <input type="date" value={dataCalc} onChange={(e) => setDataCalc(e.target.value)} disabled={running} />
                <div className="help">Reprocessar a mesma data faz UPDATE (RN-013)</div>
              </div>
              <div className="field">
                <label>Disparado por</label>
                <input value={D3.USUARIO.login} className="mono" disabled />
              </div>
            </div>

            <div className="hr"></div>

            <div style={{ display: "grid", gridTemplateColumns: "repeat(4, 1fr)", gap: 10 }}>
              <div className="stat" style={{ padding: 12 }}>
                <div className="k" style={{ fontSize: 10 }}>Posições abertas</div>
                <div className="v" style={{ fontSize: 22, marginTop: 6 }}>{posicoes.length}</div>
              </div>
              <div className="stat" style={{ padding: 12 }}>
                <div className="k" style={{ fontSize: 10 }}>Preços OK</div>
                <div className="v" style={{ fontSize: 22, marginTop: 6, color: "var(--pos)" }}>6/6</div>
              </div>
              <div className="stat" style={{ padding: 12 }}>
                <div className="k" style={{ fontSize: 10 }}>Câmbio</div>
                <div className="v" style={{ fontSize: 22, marginTop: 6 }}>5.124</div>
              </div>
              <div className="stat" style={{ padding: 12 }}>
                <div className="k" style={{ fontSize: 10 }}>Vencem hoje</div>
                <div className="v" style={{ fontSize: 22, marginTop: 6 }}>0</div>
              </div>
            </div>

            <div className="hr"></div>

            <div className="doc-strip" style={{ marginBottom: 14 }}>
              <b>RN-011</b> processa apenas posições com status ABERTA.<br />
              <b>RN-013</b> idempotente · UPSERT por (posicao_id, data_calculo).<br />
              <b>RN-015</b> resultado convertido para BRL via câmbio do dia.
            </div>

            <button className="btn primary" disabled={running} onClick={disparar}
                    style={{ width: "100%", justifyContent: "center", padding: "12px", fontSize: 13 }}>
              {running
                ? <><Icon name="refresh" /> Processando… {Math.round(progress)}%</>
                : <><Icon name="play" size={13} /> Disparar motor para {fmtDate(dataCalc)}</>}
            </button>

            {(running || resultado) && (
              <div style={{ marginTop: 12, height: 4, background: "var(--bg-2)", borderRadius: 2, overflow: "hidden" }}>
                <div style={{ width: progress + "%", background: "var(--amber)", height: "100%", transition: "width 200ms" }}></div>
              </div>
            )}
          </div>
        </div>

        {/* Log de execução */}
        <div className="panel">
          <div className="panel-head">
            <h3>Log da execução</h3>
            <div className="meta">{logLines.length} linhas · stream</div>
          </div>
          <div style={{ padding: 14, height: 320, overflow: "auto", background: "oklch(0.16 0.012 60)", fontFamily: "var(--mono)", fontSize: 11.5, lineHeight: 1.65 }}>
            {logLines.length === 0 && !resultado && (
              <div style={{ color: "var(--fg-3)" }}>
                <div># aguardando disparo</div>
                <div># pressione "Disparar motor" para iniciar</div>
              </div>
            )}
            {logLines.map((l, i) => (
              <div key={i} style={{
                color: l.includes("falha") ? "var(--neg)" :
                       l.includes("ok") ? "var(--pos)" :
                       l.includes("finalizado") ? "var(--amber)" :
                       "var(--fg-1)"
              }}>{l}</div>
            ))}
            {resultado && (
              <div style={{ marginTop: 8, padding: 10, background: "var(--bg-2)", borderRadius: 3, borderLeft: "3px solid var(--pos)" }}>
                <div style={{ color: "var(--pos)", fontWeight: 600 }}>✓ execução #{resultado.execucao_id} concluída</div>
                <div style={{ color: "var(--fg-2)", marginTop: 4 }}>
                  {resultado.sucessos}/{resultado.posicoes_processadas} sucessos · {(resultado.duracao_ms/1000).toFixed(2)}s · 0 falhas
                </div>
              </div>
            )}
          </div>
        </div>
      </div>

      {/* Histórico */}
      <div style={{ display: "grid", gridTemplateColumns: "1.4fr 1fr", gap: 14 }}>
        <div className="panel">
          <div className="panel-head">
            <h3>Histórico de execuções</h3>
            <div className="meta">GET /motor/execucoes</div>
          </div>
          <table className="t dense">
            <thead><tr>
              <th>#</th>
              <th>Data cálculo</th>
              <th>Disparo</th>
              <th>Por</th>
              <th className="num">Duração</th>
              <th className="num">Posições</th>
              <th>Resultado</th>
              <th></th>
            </tr></thead>
            <tbody>
              {D3.EXECUCOES.slice().reverse().map((ex) => (
                <tr key={ex.id} onClick={() => setExSel(ex)}
                    style={{ cursor: "pointer", background: exSel?.id === ex.id ? "var(--bg-2)" : undefined }}>
                  <td className="mono" style={{ color: "var(--fg-3)" }}>{ex.id}</td>
                  <td className="mono">{fmtDate(ex.data_calculo)}</td>
                  <td className="mono" style={{ color: "var(--fg-2)" }}>{ex.iniciado_em.split(" ")[1]}</td>
                  <td className="mono" style={{ color: "var(--fg-2)" }}>{ex.usuario}</td>
                  <td className="num">{(ex.duracao_ms/1000).toFixed(1)}s</td>
                  <td className="num">{ex.sucessos}/{ex.posicoes_processadas}</td>
                  <td>
                    {ex.falhas.length === 0
                      ? <span className="pill pos">OK</span>
                      : <span className="pill neg">{ex.falhas.length} falha</span>}
                  </td>
                  <td><Icon name="chevron" size={11} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="panel">
          <div className="panel-head">
            <h3>Detalhe · execução #{exSel.id}</h3>
            <div className="meta">{fmtDateLong(exSel.data_calculo)}</div>
          </div>
          <div className="panel-body">
            <div className="kv-grid" style={{ gridTemplateColumns: "150px 1fr" }}>
              <div className="k">Data cálculo</div>
              <div className="v">{fmtDate(exSel.data_calculo)}</div>
              <div className="k">Iniciado em</div>
              <div className="v">{exSel.iniciado_em}</div>
              <div className="k">Finalizado em</div>
              <div className="v">{exSel.finalizado_em}</div>
              <div className="k">Duração</div>
              <div className="v">{(exSel.duracao_ms/1000).toFixed(2)} s</div>
              <div className="k">Disparado por</div>
              <div className="v">{exSel.usuario}</div>
              <div className="k">Posições processadas</div>
              <div className="v">{exSel.posicoes_processadas}</div>
              <div className="k">Sucessos</div>
              <div className="v" style={{ color: "var(--pos)" }}>{exSel.sucessos}</div>
              <div className="k">Falhas</div>
              <div className="v" style={{ color: exSel.falhas.length ? "var(--neg)" : "var(--fg-2)" }}>{exSel.falhas.length}</div>
            </div>

            {exSel.falhas.length > 0 && (
              <div style={{ marginTop: 14 }}>
                <div style={{ fontSize: 11, color: "var(--neg)", textTransform: "uppercase", letterSpacing: "0.08em", fontWeight: 600, marginBottom: 8 }}>
                  Falhas registradas
                </div>
                {exSel.falhas.map((f, i) => (
                  <div key={i} style={{ display: "flex", alignItems: "flex-start", gap: 8, padding: 10, background: "var(--neg-bg)", borderRadius: 3, fontSize: 12, marginBottom: 6 }}>
                    <Icon name="warn" size={14} />
                    <div>
                      <div style={{ fontFamily: "var(--mono)", fontWeight: 600 }}>posição #{f.posicao_id}</div>
                      <div style={{ color: "var(--fg-1)", marginTop: 2 }}>{f.motivo}</div>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}

// ============================================================
// RELATÓRIO · POSIÇÃO ABERTA
// ============================================================
function RelPosicaoAbertaScreen() {
  const [dataRef, setDataRef] = useState(D3.HOJE);
  const [agrup, setAgrup] = useState("produto");

  const rows = useMemo(() => {
    const abertas = D3.POSICOES.filter((p) => p.status === "ABERTA");
    return abertas.map((p) => {
      const prod = D3.PRODUTOS.find((x) => x.id === p.produto_id);
      const m = D3.mtmDe(p.id, dataRef);
      return {
        ...p,
        produto: prod,
        mtm: m?.mtm_valor || 0,
        var: m?.variacao_dia || 0,
        preco: m?.preco_mercado || 0,
      };
    });
  }, [dataRef]);

  const grupos = useMemo(() => {
    const map = {};
    for (const r of rows) {
      const key = agrup === "produto" ? r.produto.nome : r.instrumento;
      map[key] = map[key] || [];
      map[key].push(r);
    }
    return map;
  }, [rows, agrup]);

  const totalMtm = rows.reduce((s, r) => s + r.mtm, 0);
  const totalVar = rows.reduce((s, r) => s + r.var, 0);

  return (
    <div className="page" data-screen-label="08 Rel Posicao Aberta">
      <div className="page-head">
        <div>
          <div className="crumb">Relatórios · Posição aberta</div>
          <h1>Posição aberta consolidada</h1>
          <div className="sub">Snapshot em {fmtDateLong(dataRef)} · {rows.length} posições · RN-016</div>
        </div>
        <div style={{ display: "flex", gap: 8 }}>
          <input type="date" value={dataRef} onChange={(e) => setDataRef(e.target.value)} style={{ width: 160 }} />
          <div className="seg">
            <button className={agrup === "produto" ? "on" : ""} onClick={() => setAgrup("produto")}>POR PRODUTO</button>
            <button className={agrup === "tipo" ? "on" : ""} onClick={() => setAgrup("tipo")}>POR TIPO</button>
          </div>
          <button className="btn"><Icon name="download" /> CSV</button>
          <button className="btn"><Icon name="download" /> PDF</button>
        </div>
      </div>

      <div style={{ display: "grid", gridTemplateColumns: "repeat(3, 1fr)", gap: 14, marginBottom: 18 }}>
        <div className="stat">
          <div className="k">Posições abertas</div>
          <div className="v">{rows.length}</div>
        </div>
        <div className="stat">
          <div className="k">MtM consolidado · BRL</div>
          <div className="v" style={{ color: totalMtm >= 0 ? "var(--pos)" : "var(--neg)" }}>{fmtBRL(totalMtm, { decimals: 0 })}</div>
        </div>
        <div className="stat">
          <div className="k">Variação do dia</div>
          <div className="v" style={{ color: totalVar >= 0 ? "var(--pos)" : "var(--neg)" }}>{fmtBRL(totalVar, { sign: true, decimals: 0 })}</div>
        </div>
      </div>

      {Object.entries(grupos).map(([gname, items]) => {
        const subTotMtm = items.reduce((s, r) => s + r.mtm, 0);
        const subTotVar = items.reduce((s, r) => s + r.var, 0);
        return (
          <div className="panel" key={gname} style={{ marginBottom: 14 }}>
            <div className="panel-head">
              <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
                <h3>{gname}</h3>
                <span className="pill muted">{items.length} posições</span>
              </div>
              <div style={{ display: "flex", gap: 18, fontFamily: "var(--mono)", fontSize: 12 }}>
                <div>
                  <span style={{ color: "var(--fg-3)" }}>subtotal MtM </span>
                  <span style={{ color: subTotMtm >= 0 ? "var(--pos)" : "var(--neg)", fontWeight: 600 }}>{fmtBRL(subTotMtm, { decimals: 0 })}</span>
                </div>
                <div>
                  <span style={{ color: "var(--fg-3)" }}>Δ D−1 </span>
                  <span style={{ color: subTotVar >= 0 ? "var(--pos)" : "var(--neg)", fontWeight: 600 }}>{fmtSignedNum(subTotVar, 0)}</span>
                </div>
              </div>
            </div>
            <table className="t dense">
              <thead><tr>
                <th>#</th>
                <th>{agrup === "produto" ? "Tipo" : "Produto"}</th>
                <th>Lado</th>
                <th className="num">Quantidade</th>
                <th className="num">Preço entrada</th>
                <th className="num">Preço mercado</th>
                <th>Vencimento</th>
                <th className="num">MtM (BRL)</th>
                <th className="num">Δ D−1</th>
              </tr></thead>
              <tbody>
                {items.map((r) => (
                  <tr key={r.id}>
                    <td className="mono" style={{ color: "var(--fg-3)" }}>{r.id}</td>
                    <td>{agrup === "produto" ? <InstrumentoPill tipo={r.instrumento} /> : r.produto.nome}</td>
                    <td><LadoPill lado={r.lado} /></td>
                    <td className="num">{r.instrumento === "NDF" ? fmtNum(r.extra.valor_nocional, 0) : fmtNum(r.quantidade, 0)}</td>
                    <td className="num">{
                      r.instrumento === "OPCAO" ? fmtNum(r.extra.strike, 2)
                      : r.instrumento === "NDF" ? fmtNum(r.extra.taxa_contratada, 4)
                      : fmtNum(r.extra.preco_entrada, 4)
                    }</td>
                    <td className="num">{fmtNum(r.preco, 4)}</td>
                    <td className="mono" style={{ color: "var(--fg-2)" }}>{fmtDate(r.data_vencimento)}</td>
                    <td className="num" style={{ color: r.mtm >= 0 ? "var(--pos)" : "var(--neg)", fontWeight: 500 }}>{fmtBRL(r.mtm, { decimals: 0 })}</td>
                    <td className="num" style={{ color: r.var >= 0 ? "var(--pos)" : "var(--neg)" }}>{fmtSignedNum(r.var, 0)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        );
      })}
    </div>
  );
}

// ============================================================
// RELATÓRIO · P&L
// ============================================================
function RelPLScreen() {
  const [janela, setJanela] = useState("12d");

  const dias = D3.DATAS_PREGAO;
  const plDiario = dias.map((d) =>
    D3.MTM.filter((m) => m.data_calculo === d).reduce((s, m) => s + m.variacao_dia, 0)
  );
  const plAcum = dias.map((d) =>
    D3.MTM.filter((m) => m.data_calculo === d).reduce((s, m) => s + m.mtm_valor, 0)
  );

  const ultimo = plAcum[plAcum.length - 1];
  const varHoje = plDiario[plDiario.length - 1];
  const melhorDia = Math.max(...plDiario);
  const piorDia = Math.min(...plDiario);

  // Por posição no último dia
  const porPos = useMemo(() => {
    return D3.POSICOES.filter((p) => p.status === "ABERTA").map((p) => {
      const prod = D3.PRODUTOS.find((x) => x.id === p.produto_id);
      const hist = D3.MTM.filter((m) => m.posicao_id === p.id);
      const ultimoM = hist[hist.length - 1];
      return {
        ...p,
        produto: prod,
        mtm: ultimoM?.mtm_valor || 0,
        var: ultimoM?.variacao_dia || 0,
        hist: hist.map((h) => h.mtm_valor),
      };
    }).sort((a, b) => b.mtm - a.mtm);
  }, []);

  return (
    <div className="page" data-screen-label="09 Rel P&L">
      <div className="page-head">
        <div>
          <div className="crumb">Relatórios · P&amp;L</div>
          <h1>P&amp;L diário e acumulado</h1>
          <div className="sub">RN-017 soma variacao_dia · RN-018 soma mtm_valor das abertas · janela {janela}</div>
        </div>
        <div style={{ display: "flex", gap: 8 }}>
          <div className="seg">
            {["5d", "12d", "MTD", "YTD"].map((j) => (
              <button key={j} className={janela === j ? "on" : ""} onClick={() => setJanela(j)}>{j}</button>
            ))}
          </div>
          <button className="btn"><Icon name="download" /> CSV</button>
        </div>
      </div>

      <div style={{ display: "grid", gridTemplateColumns: "repeat(4, 1fr)", gap: 14, marginBottom: 18 }}>
        <div className="stat">
          <div className="k">P&L acumulado</div>
          <div className="v" style={{ color: ultimo >= 0 ? "var(--pos)" : "var(--neg)" }}>{fmtBRL(ultimo, { decimals: 0 })}</div>
        </div>
        <div className="stat">
          <div className="k">P&L do dia</div>
          <div className="v" style={{ color: varHoje >= 0 ? "var(--pos)" : "var(--neg)" }}>{fmtBRL(varHoje, { sign: true, decimals: 0 })}</div>
        </div>
        <div className="stat">
          <div className="k">Melhor pregão</div>
          <div className="v" style={{ color: "var(--pos)" }}>{fmtBRL(melhorDia, { decimals: 0 })}</div>
          <div className="delta" style={{ color: "var(--fg-2)" }}>{fmtDate(dias[plDiario.indexOf(melhorDia)])}</div>
        </div>
        <div className="stat">
          <div className="k">Pior pregão</div>
          <div className="v" style={{ color: "var(--neg)" }}>{fmtBRL(piorDia, { decimals: 0 })}</div>
          <div className="delta" style={{ color: "var(--fg-2)" }}>{fmtDate(dias[plDiario.indexOf(piorDia)])}</div>
        </div>
      </div>

      <div style={{ display: "grid", gridTemplateColumns: "1fr", gap: 14, marginBottom: 14 }}>
        <div className="panel">
          <div className="panel-head">
            <h3>P&amp;L acumulado · BRL</h3>
            <div className="meta">soma de mtm_valor por dia</div>
          </div>
          <div style={{ padding: "12px 16px 18px" }}>
            <LineChart series={[{
              values: plAcum,
              labels: dias.map((d) => d.slice(5).replace("-", "/")),
              color: "var(--amber)",
              fill: true,
            }]} height={260} />
          </div>
        </div>
        <div className="panel">
          <div className="panel-head">
            <h3>P&amp;L diário · BRL</h3>
            <div className="meta">soma de variacao_dia</div>
          </div>
          <div style={{ padding: "12px 16px 18px" }}>
            <BarChart data={plDiario} labels={dias.map((d) => d.slice(5).replace("-", "/"))} height={220} />
          </div>
        </div>
      </div>

      <div className="panel">
        <div className="panel-head">
          <h3>Detalhe por posição</h3>
          <div className="meta">ordenado por MtM desc</div>
        </div>
        <table className="t dense">
          <thead><tr>
            <th>#</th>
            <th>Produto</th>
            <th>Tipo</th>
            <th>Lado</th>
            <th>Histórico</th>
            <th className="num">MtM</th>
            <th className="num">Δ D−1</th>
          </tr></thead>
          <tbody>
            {porPos.map((p) => (
              <tr key={p.id}>
                <td className="mono" style={{ color: "var(--fg-3)" }}>{p.id}</td>
                <td>
                  <div style={{ fontWeight: 500 }}>{p.produto.nome}</div>
                  <div style={{ fontSize: 11, color: "var(--fg-3)", fontFamily: "var(--mono)" }}>
                    {p.extra.codigo_contrato || p.extra.indexador || (p.extra.tipo_opcao ? `${p.extra.tipo_opcao} K${fmtNum(p.extra.strike, 2)}` : "")}
                  </div>
                </td>
                <td><InstrumentoPill tipo={p.instrumento} /></td>
                <td><LadoPill lado={p.lado} /></td>
                <td style={{ width: 130 }}>
                  <Sparkline data={p.hist} width={120} height={26}
                    color={p.mtm >= 0 ? "var(--pos)" : "var(--neg)"} />
                </td>
                <td className="num" style={{ color: p.mtm >= 0 ? "var(--pos)" : "var(--neg)", fontWeight: 500 }}>
                  {fmtBRL(p.mtm, { decimals: 0 })}
                </td>
                <td className="num" style={{ color: p.var >= 0 ? "var(--pos)" : "var(--neg)" }}>
                  {fmtSignedNum(p.var, 0)}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

// ============================================================
// RELATÓRIO · EXPOSIÇÃO LÍQUIDA
// ============================================================
function RelExposicaoScreen() {
  const dataRef = D3.HOJE;
  const exposicao = useMemo(() => {
    const map = {};
    for (const p of D3.POSICOES.filter((p) => p.status === "ABERTA")) {
      const k = p.produto_id;
      const prod = D3.PRODUTOS.find((x) => x.id === k);
      map[k] = map[k] || {
        produto_id: k, produto: prod,
        comprado: 0, vendido: 0,
        mtm: 0, posicoes: 0,
        breakdown: { FUTURO: 0, NDF: 0, OPCAO: 0, OTC: 0 },
      };
      const q = p.instrumento === "NDF" ? p.extra.valor_nocional : p.quantidade;
      if (p.lado === "COMPRADO") map[k].comprado += q;
      else map[k].vendido += q;
      map[k].posicoes += 1;
      map[k].breakdown[p.instrumento] += 1;
      const m = D3.mtmDe(p.id, dataRef);
      map[k].mtm += m?.mtm_valor || 0;
    }
    return Object.values(map);
  }, []);

  const maxAbs = Math.max(...exposicao.flatMap((e) => [e.comprado, e.vendido]));

  return (
    <div className="page" data-screen-label="10 Rel Exposicao">
      <div className="page-head">
        <div>
          <div className="crumb">Relatórios · Exposição líquida</div>
          <h1>Exposição líquida por produto</h1>
          <div className="sub">Em {fmtDateLong(dataRef)} · RN-019 soma de (quantidade × sinal)</div>
        </div>
        <div style={{ display: "flex", gap: 8 }}>
          <button className="btn"><Icon name="download" /> CSV</button>
          <button className="btn"><Icon name="download" /> PDF</button>
        </div>
      </div>

      <div className="panel" style={{ marginBottom: 14 }}>
        <div className="panel-head">
          <h3>Visão consolidada</h3>
          <div className="meta">comprado vs. vendido · agrupado por produto</div>
        </div>
        <table className="t">
          <thead><tr>
            <th>Produto</th>
            <th>Mix de instrumentos</th>
            <th className="num">Comprado</th>
            <th className="num">Vendido</th>
            <th style={{ width: 280 }}>Balanço</th>
            <th className="num">Líquido</th>
            <th className="num">MtM (BRL)</th>
          </tr></thead>
          <tbody>
            {exposicao.map((e) => {
              const liq = e.comprado - e.vendido;
              const wPos = (e.comprado / maxAbs) * 100;
              const wNeg = (e.vendido / maxAbs) * 100;
              return (
                <tr key={e.produto_id}>
                  <td>
                    <div style={{ fontWeight: 500 }}>{e.produto.nome}</div>
                    <div style={{ fontSize: 11, color: "var(--fg-3)", fontFamily: "var(--mono)" }}>
                      {e.produto.bolsa_ref} · {e.produto.unidade} · {e.posicoes} posições
                    </div>
                  </td>
                  <td>
                    <div style={{ display: "flex", gap: 4, flexWrap: "wrap" }}>
                      {Object.entries(e.breakdown).filter(([_, n]) => n > 0).map(([k, n]) => (
                        <span key={k} className="pill muted" style={{ padding: "1px 6px" }}>
                          {k} <span style={{ color: "var(--amber)" }}>×{n}</span>
                        </span>
                      ))}
                    </div>
                  </td>
                  <td className="num">{fmtNum(e.comprado, 0)}</td>
                  <td className="num">{fmtNum(e.vendido, 0)}</td>
                  <td>
                    <div className="expbar">
                      <div className="seg-neg" style={{ width: (wNeg / 2) + "%" }}></div>
                      <div className="seg-pos" style={{ width: (wPos / 2) + "%" }}></div>
                      <div className="center"></div>
                    </div>
                  </td>
                  <td className="num" style={{ color: liq >= 0 ? "var(--pos)" : "var(--neg)", fontWeight: 600 }}>
                    {fmtSignedNum(liq, 0)}
                  </td>
                  <td className="num" style={{ color: e.mtm >= 0 ? "var(--pos)" : "var(--neg)" }}>
                    {fmtBRL(e.mtm, { decimals: 0 })}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      {/* Tile grid por produto */}
      <div style={{ display: "grid", gridTemplateColumns: "repeat(3, 1fr)", gap: 14 }}>
        {exposicao.map((e) => {
          const liq = e.comprado - e.vendido;
          return (
            <div className="panel" key={e.produto_id} style={{ padding: 16 }}>
              <div style={{ display: "flex", justifyContent: "space-between", alignItems: "flex-start", marginBottom: 12 }}>
                <div>
                  <div style={{ fontWeight: 500, fontSize: 13 }}>{e.produto.nome}</div>
                  <div style={{ fontSize: 11, color: "var(--fg-3)", fontFamily: "var(--mono)", marginTop: 2 }}>
                    {e.produto.bolsa_ref} · {e.produto.moeda_cotacao}
                  </div>
                </div>
                <span className={"pill " + (liq >= 0 ? "pos" : "neg")} style={{ fontSize: 11 }}>
                  {liq >= 0 ? "NET LONG" : "NET SHORT"}
                </span>
              </div>
              <div style={{ display: "flex", justifyContent: "space-between", padding: "6px 0", fontSize: 12 }}>
                <span style={{ color: "var(--fg-2)" }}>Comprado</span>
                <span className="mono pos-text">{fmtNum(e.comprado, 0)}</span>
              </div>
              <div style={{ display: "flex", justifyContent: "space-between", padding: "6px 0", fontSize: 12 }}>
                <span style={{ color: "var(--fg-2)" }}>Vendido</span>
                <span className="mono neg-text">{fmtNum(e.vendido, 0)}</span>
              </div>
              <div className="hr" style={{ margin: "8px 0" }}></div>
              <div style={{ display: "flex", justifyContent: "space-between", padding: "2px 0", fontSize: 13 }}>
                <span style={{ color: "var(--fg-1)", fontWeight: 500 }}>Líquido</span>
                <span className="mono" style={{ color: liq >= 0 ? "var(--pos)" : "var(--neg)", fontWeight: 600 }}>{fmtSignedNum(liq, 0)} {e.produto.unidade}</span>
              </div>
              <div style={{ display: "flex", justifyContent: "space-between", padding: "4px 0", fontSize: 12 }}>
                <span style={{ color: "var(--fg-3)" }}>MtM</span>
                <span className="mono" style={{ color: e.mtm >= 0 ? "var(--pos)" : "var(--neg)" }}>{fmtBRL(e.mtm, { decimals: 0 })}</span>
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}

Object.assign(window, { MotorScreen, RelPosicaoAbertaScreen, RelPLScreen, RelExposicaoScreen });
