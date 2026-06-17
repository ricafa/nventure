/* global window */
// Mock dataset para o MVP de risco de mercado.
// Datas centradas em torno de 2026-05-25 (segunda do pregão de referência).

const PRODUTOS = [
  { id: 1, nome: "Soja CBOT",      unidade: "bushel",    bolsa_ref: "CBOT",  moeda_cotacao: "USD", ativo: true,  contratos: 142 },
  { id: 2, nome: "Milho B3",       unidade: "saca 60kg", bolsa_ref: "B3",    moeda_cotacao: "BRL", ativo: true,  contratos: 88  },
  { id: 3, nome: "Café Arábica",   unidade: "saca 60kg", bolsa_ref: "ICE",   moeda_cotacao: "USD", ativo: true,  contratos: 54  },
  { id: 4, nome: "Açúcar #11",     unidade: "libra-peso",bolsa_ref: "ICE",   moeda_cotacao: "USD", ativo: true,  contratos: 31  },
  { id: 5, nome: "Boi Gordo",      unidade: "arroba",    bolsa_ref: "B3",    moeda_cotacao: "BRL", ativo: true,  contratos: 22  },
  { id: 6, nome: "Etanol Hidratado",unidade: "m³",       bolsa_ref: "B3",    moeda_cotacao: "BRL", ativo: true,  contratos: 18  },
  { id: 7, nome: "WTI Crude",      unidade: "barril",    bolsa_ref: "NYMEX", moeda_cotacao: "USD", ativo: false, contratos: 0   },
];

// Série de preços dos últimos 12 pregões para cada produto
const _seed = (id) => (n) => {
  // gerador pseudo-aleatório determinístico
  let x = Math.sin((id * 9301 + n * 49297) % 233280) * 43758.5453;
  return x - Math.floor(x);
};

const _datas = [];
{
  const base = new Date("2026-05-25T00:00:00Z");
  for (let i = 11; i >= 0; i--) {
    const d = new Date(base);
    d.setUTCDate(base.getUTCDate() - i);
    // pula fim de semana
    while (d.getUTCDay() === 0 || d.getUTCDay() === 6) {
      d.setUTCDate(d.getUTCDate() - 1);
    }
    _datas.push(d.toISOString().slice(0, 10));
  }
}
const DATAS_PREGAO = [..._datas];
const HOJE = DATAS_PREGAO[DATAS_PREGAO.length - 1]; // 2026-05-25

const _basePreco = {
  1: 1418.5,  // Soja CBOT em cents/bushel ~ usamos USD/bushel*100
  2: 71.2,    // Milho R$/saca
  3: 218.4,   // Café USD/saca
  4: 0.184,   // Açúcar USD/lb
  5: 312.5,   // Boi R$/@
  6: 2940.0,  // Etanol R$/m³
  7: 78.4,    // WTI USD/bbl
};

const _baseCambio = 5.12;

function gerarSeriePrecos() {
  const out = [];
  let pid_id = 1;
  for (const p of PRODUTOS) {
    const rnd = _seed(p.id);
    let preco = _basePreco[p.id];
    let cambio = _baseCambio;
    for (let i = 0; i < DATAS_PREGAO.length; i++) {
      const drift = (rnd(i) - 0.5) * 0.025;
      preco = preco * (1 + drift);
      cambio = cambio * (1 + (rnd(i + 100) - 0.5) * 0.004);
      out.push({
        id: pid_id++,
        produto_id: p.id,
        data_preco: DATAS_PREGAO[i],
        preco_fechamento: +preco.toFixed(p.moeda_cotacao === "USD" ? 4 : 2),
        cambio_brl: +cambio.toFixed(4),
        vol_implicita: null,
        taxa_juros: null,
        criado_em: DATAS_PREGAO[i] + " 18:32:11",
      });
    }
  }
  return out;
}

const PRECOS = gerarSeriePrecos();

function precoEm(produto_id, data) {
  return PRECOS.find((p) => p.produto_id === produto_id && p.data_preco === data);
}

// POSIÇÕES — mix dos quatro tipos
const POSICOES = [
  // Futuros
  { id: 1001, produto_id: 1, instrumento: "FUTURO", mercado: "BOLSA", lado: "COMPRADO", quantidade: 50,
    data_entrada: "2026-04-12", data_vencimento: "2026-09-15", contraparte: null, status: "ABERTA",
    criado_por: "m.duarte", observacoes: "Hedge safra 24/25 — núcleo da posição",
    extra: { preco_entrada: 1402.50, codigo_contrato: "ZSU24" } },

  { id: 1002, produto_id: 3, instrumento: "FUTURO", mercado: "BOLSA", lado: "COMPRADO", quantidade: 30,
    data_entrada: "2026-05-04", data_vencimento: "2026-07-20", contraparte: null, status: "ABERTA",
    criado_por: "r.kishimoto",
    extra: { preco_entrada: 212.80, codigo_contrato: "KCN26" } },

  { id: 1003, produto_id: 5, instrumento: "FUTURO", mercado: "BOLSA", lado: "VENDIDO", quantidade: 120,
    data_entrada: "2026-03-28", data_vencimento: "2026-10-31", contraparte: null, status: "ABERTA",
    criado_por: "m.duarte",
    extra: { preco_entrada: 318.40, codigo_contrato: "BGIV26" } },

  // NDFs
  { id: 1004, produto_id: 1, instrumento: "NDF", mercado: "BALCAO", lado: "VENDIDO", quantidade: 1,
    data_entrada: "2026-05-08", data_vencimento: "2026-08-08", contraparte: "Banco Itaú BBA", status: "ABERTA",
    criado_por: "r.kishimoto", observacoes: "NDF de USD/BRL ligado à exportação set/26",
    extra: { taxa_contratada: 5.18, valor_nocional: 2400000, moeda_nocional: "USD" } },

  { id: 1005, produto_id: 2, instrumento: "NDF", mercado: "BALCAO", lado: "COMPRADO", quantidade: 1,
    data_entrada: "2026-05-15", data_vencimento: "2026-11-15", contraparte: "Banco BTG Pactual", status: "ABERTA",
    criado_por: "f.medeiros",
    extra: { taxa_contratada: 72.40, valor_nocional: 8800, moeda_nocional: "BRL" } },

  // Opções
  { id: 1006, produto_id: 3, instrumento: "OPCAO", mercado: "BOLSA", lado: "COMPRADO", quantidade: 80,
    data_entrada: "2026-05-02", data_vencimento: "2026-07-15", contraparte: null, status: "ABERTA",
    criado_por: "m.duarte", observacoes: "Cobertura de upside para café",
    extra: { tipo_opcao: "CALL", estilo: "EUROPEIA", strike: 220.00, premio_pago: 4.85 } },

  { id: 1007, produto_id: 1, instrumento: "OPCAO", mercado: "BOLSA", lado: "COMPRADO", quantidade: 40,
    data_entrada: "2026-05-10", data_vencimento: "2026-09-20", contraparte: null, status: "ABERTA",
    criado_por: "r.kishimoto",
    extra: { tipo_opcao: "PUT", estilo: "EUROPEIA", strike: 1390.00, premio_pago: 18.20 } },

  { id: 1008, produto_id: 5, instrumento: "OPCAO", mercado: "BOLSA", lado: "VENDIDO", quantidade: 60,
    data_entrada: "2026-04-22", data_vencimento: "2026-06-30", contraparte: null, status: "ABERTA",
    criado_por: "f.medeiros",
    extra: { tipo_opcao: "CALL", estilo: "AMERICANA", strike: 325.00, premio_pago: 6.10 } },

  // OTC
  { id: 1009, produto_id: 2, instrumento: "OTC", mercado: "BALCAO", lado: "VENDIDO", quantidade: 5000,
    data_entrada: "2026-04-30", data_vencimento: "2026-12-15", contraparte: "Cargill Trading", status: "ABERTA",
    criado_por: "m.duarte", observacoes: "Pré-fixação de venda de milho safrinha",
    extra: { preco_entrada: 70.10, indexador: "CEPEA_MILHO_ESALQ", premio_otc: -0.50 } },

  { id: 1010, produto_id: 6, instrumento: "OTC", mercado: "BALCAO", lado: "COMPRADO", quantidade: 800,
    data_entrada: "2026-05-06", data_vencimento: "2026-10-30", contraparte: "Raízen Comercializadora", status: "ABERTA",
    criado_por: "f.medeiros",
    extra: { preco_entrada: 2880.00, indexador: "CEPEA_ETANOL", premio_otc: 25.00 } },

  { id: 1011, produto_id: 4, instrumento: "FUTURO", mercado: "BOLSA", lado: "VENDIDO", quantidade: 25,
    data_entrada: "2026-05-20", data_vencimento: "2026-10-15", contraparte: null, status: "ABERTA",
    criado_por: "r.kishimoto",
    extra: { preco_entrada: 0.1872, codigo_contrato: "SBV26" } },

  // Encerrada
  { id: 1012, produto_id: 1, instrumento: "FUTURO", mercado: "BOLSA", lado: "COMPRADO", quantidade: 20,
    data_entrada: "2026-02-10", data_vencimento: "2026-05-15", contraparte: null, status: "ENCERRADA",
    criado_por: "m.duarte",
    extra: { preco_entrada: 1380.00, codigo_contrato: "ZSK24" } },
];

// Calcula MtM polimórfico (espelha o motor da spec).
function calcularMtmMoedaOrig(pos, precoMercado) {
  const sinal = pos.lado === "COMPRADO" ? 1 : -1;
  switch (pos.instrumento) {
    case "FUTURO": {
      return (precoMercado - pos.extra.preco_entrada) * pos.quantidade * sinal;
    }
    case "NDF": {
      return (precoMercado - pos.extra.taxa_contratada) * pos.extra.valor_nocional * sinal;
    }
    case "OPCAO": {
      const vi = pos.extra.tipo_opcao === "CALL"
        ? Math.max(precoMercado - pos.extra.strike, 0)
        : Math.max(pos.extra.strike - precoMercado, 0);
      return (vi - pos.extra.premio_pago) * pos.quantidade * sinal;
    }
    case "OTC": {
      const efetivo = precoMercado + pos.extra.premio_otc;
      return (efetivo - pos.extra.preco_entrada) * pos.quantidade * sinal;
    }
    default:
      return 0;
  }
}

// Gera série de MtM para cada posição ao longo dos dias.
function gerarMtm() {
  const out = [];
  for (const pos of POSICOES) {
    if (pos.status === "ENCERRADA") continue;
    let mtmOntem = 0;
    for (const data of DATAS_PREGAO) {
      if (data < pos.data_entrada) continue;
      const pr = precoEm(pos.produto_id, data);
      if (!pr) continue;
      const mtmOrig = calcularMtmMoedaOrig(pos, pr.preco_fechamento);
      const produto = PRODUTOS.find((p) => p.id === pos.produto_id);
      const mtmBrl = produto.moeda_cotacao === "USD"
        ? mtmOrig * pr.cambio_brl
        : mtmOrig;
      const variacao = data === pos.data_entrada ? 0 : mtmBrl - mtmOntem;
      out.push({
        posicao_id: pos.id,
        data_calculo: data,
        preco_mercado: pr.preco_fechamento,
        mtm_valor: +mtmBrl.toFixed(2),
        variacao_dia: +variacao.toFixed(2),
        pl_acumulado: +mtmBrl.toFixed(2),
      });
      mtmOntem = mtmBrl;
    }
  }
  return out;
}

const MTM = gerarMtm();

function mtmDe(posicao_id, data) {
  return MTM.find((m) => m.posicao_id === posicao_id && m.data_calculo === data);
}

// Histórico de execuções do motor
const EXECUCOES = DATAS_PREGAO.slice(-8).map((d, i) => ({
  id: 40 + i,
  data_calculo: d,
  iniciado_em: d + " 18:34:02",
  finalizado_em: d + " 18:34:" + String(8 + Math.floor(Math.random() * 12)).padStart(2, "0"),
  duracao_ms: 6100 + Math.floor(Math.random() * 4200),
  posicoes_processadas: 11,
  sucessos: i === 2 ? 10 : 11,
  falhas: i === 2 ? [{ posicao_id: 1008, motivo: "Preço de fechamento ausente para Boi Gordo nesta data" }] : [],
  usuario: ["m.duarte", "r.kishimoto", "f.medeiros"][i % 3],
}));

const USUARIO = {
  login: "m.duarte",
  nome: "Marina Duarte",
  perfil: "OPERADOR",
  mesa: "Risco · Commodities",
};

window.__APP_DATA__ = {
  PRODUTOS, PRECOS, POSICOES, MTM, EXECUCOES, USUARIO,
  DATAS_PREGAO, HOJE,
  precoEm, mtmDe, calcularMtmMoedaOrig,
};
