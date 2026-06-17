/* global React, ReactDOM, window */
const { useState, useEffect } = React;
const Data = window.__APP_DATA__;

function Shell() {
  const [route, setRoute] = useState("dashboard");
  const [logged, setLogged] = useState(true); // começa logado para mostrar app rápido
  const [pregaoState] = useState("CALCULADO"); // status do motor

  if (!logged) {
    return <LoginScreen onLogin={() => setLogged(true)} />;
  }

  let body;
  switch (route) {
    case "dashboard":     body = <DashboardScreen goto={setRoute} />; break;
    case "produtos":      body = <ProdutosScreen />; break;
    case "precos":        body = <PrecosScreen />; break;
    case "nova-posicao":  body = <NovaPosicaoScreen />; break;
    case "posicoes":      body = <PosicoesScreen goto={setRoute} />; break;
    case "motor":         body = <MotorScreen />; break;
    case "rel-posicao":   body = <RelPosicaoAbertaScreen />; break;
    case "rel-pl":        body = <RelPLScreen />; break;
    case "rel-exposicao": body = <RelExposicaoScreen />; break;
    default:              body = <DashboardScreen goto={setRoute} />;
  }

  return (
    <div className="app">
      {/* Top bar */}
      <div className="topbar">
        <div className="brand">
          <span className="brand-mark"></span>
          <span>NeverVenture</span>
          <span style={{ color: "var(--fg-3)", fontFamily: "var(--mono)", fontSize: 11, fontWeight: 400, marginLeft: 6 }}>
            / risk.commodities
          </span>
        </div>
        <div className="topbar-status">
          <div>
            <span style={{ color: "var(--fg-3)" }}>Pregão </span>
            <span className="mono">{Data.HOJE}</span>
          </div>
          <span className="sep">·</span>
          <div>
            <span className="pulse"></span>
            <span style={{ color: "var(--fg-3)" }}>motor </span>
            <span className="mono" style={{ color: "var(--pos)" }}>{pregaoState}</span>
          </div>
          <span className="sep">·</span>
          <div>
            <span style={{ color: "var(--fg-3)" }}>USD/BRL </span>
            <span className="mono">5.1240</span>
            <span className="mono" style={{ color: "var(--pos)", marginLeft: 6 }}>+0.08%</span>
          </div>
          <span className="sep">·</span>
          <div>
            <span style={{ color: "var(--fg-3)" }}>posições </span>
            <span className="mono">11</span>
          </div>
        </div>
        <div className="user">
          <span className="av">MD</span>
          <div>
            <div style={{ color: "var(--fg-0)" }}>{Data.USUARIO.nome}</div>
            <div style={{ color: "var(--fg-3)", fontSize: 10 }}>{Data.USUARIO.perfil} · {Data.USUARIO.mesa}</div>
          </div>
          <button className="btn xs ghost" title="Sair" onClick={() => setLogged(false)} style={{ marginLeft: 4 }}>
            <Icon name="logout" />
          </button>
        </div>
      </div>

      {/* Sidebar */}
      <nav className="sidebar">
        {NAV.map((group) => (
          <div className="nav-group" key={group.group}>
            <div className="label">{group.group}</div>
            {group.items.map((it, i) => (
              <div
                key={it.id}
                className={"nav-item" + (route === it.id ? " active" : "")}
                onClick={() => setRoute(it.id)}
              >
                <span className="ic"><Icon name={it.icon} size={14} /></span>
                <span>{it.label}</span>
                {it.id === "dashboard" && <span className="kbd">G D</span>}
                {it.id === "motor" && <span className="kbd">G M</span>}
                {it.id === "posicoes" && <span className="kbd">G P</span>}
              </div>
            ))}
          </div>
        ))}
        <div className="session">
          <div className="row"><span>versão</span><b>v1.0.0</b></div>
          <div className="row"><span>build</span><b className="mono">2026.05.25</b></div>
          <div className="row"><span>scheduler</span><b style={{ color: "var(--pos)" }}>● ativo</b></div>
          <div className="row"><span>próximo job</span><b className="mono">18:30</b></div>
        </div>
      </nav>

      {/* Main */}
      <main className="main">{body}</main>
    </div>
  );
}

ReactDOM.createRoot(document.getElementById("root")).render(<Shell />);
