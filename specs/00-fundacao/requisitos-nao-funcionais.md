# Requisitos não funcionais

Separação exigida: **Identificado** = tem base no documento (🟢) ou nos mocks (🔵). **Recomendação** = sugestão técnica (🟡), negociável.

## RNF-ARQ — Arquitetura
| Id | Tipo | Requisito |
|---|---|---|
| RNF-ARQ-01 | Identificado 🟢 | Arquitetura desacoplada: frontend Next.js + TypeScript, backend Laravel (PHP), comunicação por API REST. |
| RNF-ARQ-02 | Identificado 🟢 | Banco relacional MySQL com mapeamento pelo ORM Eloquent. |
| RNF-ARQ-03 | Identificado 🟢 | Integração do backend com API de IA generativa. |
| RNF-ARQ-04 | Identificado 🔵 | As telas não conhecem a origem dos dados: toda comunicação passa pela camada `src/lib/api` (já assim no mock). |
| RNF-ARQ-05 | Recomendação 🟡 | Provedor de IA trocável por configuração (interface `AiClient`), com implementação falsa para testes/demo. |

## RNF-USA — Usabilidade
| Id | Tipo | Requisito |
|---|---|---|
| RNF-USA-01 | Identificado 🟢 | Experiência visual agradável, estilizada com Tailwind CSS. |
| RNF-USA-02 | Identificado 🟢 | Acessível para "diferentes perfis de usuários" (letramento digital variado). |
| RNF-USA-03 | Identificado 🔵 | Linguagem de cozinha, não técnica: porções em medida caseira ("150 g, mais ou menos 5 colheres de sopa"), textos curtos e diretos, em pt-BR. |
| RNF-USA-04 | Identificado 🔵 | Onboarding em ~3 minutos ("Leva uns 3 minutos. Dá para mudar tudo depois."), com progresso visível e edição de qualquer etapa no resumo. |
| RNF-USA-05 | Identificado 🔵 | Toda alteração de conteúdo do dia pode ser desfeita (toast com "Desfazer"). |
| RNF-USA-06 | Identificado 🔵 | Movimento generoso (transições de tela, cascatas, barras que enchem, números que contam) respeitando `prefers-reduced-motion`. |
| RNF-USA-07 | Identificado 🟢 | Usabilidade medida na validação com a comunidade (SUS — RN41). Meta 🟡: SUS médio ≥ 68 (acima da média de mercado). |

## RNF-RES — Responsividade
| Id | Tipo | Requisito |
|---|---|---|
| RNF-RES-01 | Identificado 🟢 | Interface responsiva. |
| RNF-RES-02 | Identificado 🔵 | *Mobile-first*: coluna de até 430 px, áreas seguras (`area-segura-cima/baixo`), alvos de toque ≥ 44 px, `BottomNav` fixa. |
| RNF-RES-03 | Recomendação 🟡 | Em telas ≥ 768 px, a coluna fica centralizada sobre o fundo `papel` (comportamento atual); sem layout desktop dedicado no MVP. Testar em 360×640, 390×844 e 1280×800. |

## RNF-ACE — Acessibilidade
| Id | Tipo | Requisito |
|---|---|---|
| RNF-ACE-01 | Identificado 🟢🔵 | Semântica já presente nos mocks deve ser mantida: `role=radiogroup/radio/tablist/tab`, `aria-pressed`, `aria-live` em conteúdos que mudam, rótulos `sr-only`, `aria-label` em ícones. |
| RNF-ACE-02 | Recomendação 🟡 | WCAG 2.1 AA: contraste ≥ 4,5:1 no texto, foco visível (já há `outline` em `globals.css`), navegação completa por teclado, `Sheet` com *focus trap* e `Esc`. |
| RNF-ACE-03 | Recomendação 🟡 | Zero violações `serious`/`critical` do axe nas stories e nos E2E principais (CI). |
| RNF-ACE-04 | Identificado 🔵 | Alergia nunca comunicada só por cor: etiqueta textual "Alergia" (`EtiquetaAlergia`) além do vermelho `alerta`. |

## RNF-PER — Performance
| Id | Tipo | Requisito |
|---|---|---|
| RNF-PER-01 | Identificado 🔵 | Geração do plano "costuma levar uns 10 segundos": meta 🟡 p95 ≤ 30 s, com feedback de progresso; timeout 3 min (RN19). |
| RNF-PER-02 | Recomendação 🟡 | Endpoints sem IA: p95 ≤ 300 ms com 100 usuários ativos (escala da validação). |
| RNF-PER-03 | Recomendação 🟡 | Resposta do Nutri: p95 ≤ 15 s; indicador "O Nutri está montando a resposta" 🔵 durante a espera. |
| RNF-PER-04 | Recomendação 🟡 | Sem N+1: `Model::preventLazyLoading()` em dev/teste; `with()` nos Resources de dia e plano. |
| RNF-PER-05 | Recomendação 🟡 | Frontend: LCP ≤ 2,5 s em 4G no `/hoje`; animações só em `transform`/`opacity`. |
| RNF-PER-06 | Recomendação 🟡 | Atualização otimista ao marcar refeição (UI muda antes da resposta; reverte em erro). |

## RNF-SEG — Segurança
Ver `seguranca.md`. Identificados: nenhum explícito no documento. Recomendações: HTTPS, sessões seguras, posse de recursos (RN43), rate limiting, segredos fora do código.

## RNF-PRI — Privacidade
| Id | Tipo | Requisito |
|---|---|---|
| RNF-PRI-01 | Identificado 🔵 | "Ficam só no seu perfil. Ninguém da academia vê." — nenhum acesso de terceiros aos dados individuais. |
| RNF-PRI-02 | Identificado 🔵✅ | Exclusão total da conta e dos dados (RN06). |
| RNF-PRI-03 | Recomendação 🟡 | LGPD: consentimento para dados de saúde (RN03), minimização no envio à IA, exportação da pesquisa anonimizada (RN42). |

## RNF-LOG — Auditoria e logs
| Id | Tipo | Requisito |
|---|---|---|
| RNF-LOG-01 | Recomendação 🟡 | `ai_requests` para toda chamada à IA (custo, latência, falhas) — sem conteúdo. |
| RNF-LOG-02 | Recomendação 🟡 | `day_meal_changes` como trilha das alterações do dia (também usada no desfazer). |
| RNF-LOG-03 | Recomendação 🟡 | Log de aplicação diário (`daily`, 14 dias); `warning` para ação da IA descartada e plano falho; `error` para exceções. |
| RNF-LOG-04 | Recomendação 🟡 | `failed_jobs` monitorado; `php artisan queue:failed` no roteiro de operação. |

## RNF-MAN — Manutenibilidade
| Id | Tipo | Requisito |
|---|---|---|
| RNF-MAN-01 | Recomendação 🟡 | Laravel convencional (ver `arquitetura-backend.md`); regras em classes pequenas e puras sempre que possível. |
| RNF-MAN-02 | Recomendação 🟡 | Pint + Larastan (back); ESLint + TypeScript `strict` + Prettier (front) no CI. |
| RNF-MAN-03 | Recomendação 🟡 | Cobertura: ≥ 90% em `app/Services/Nutrition`, `Foods`, `Plans`, `Days`, `Nutri`; sem meta global (evita testes de fachada). |
| RNF-MAN-04 | Recomendação 🟡 | Estas specs são atualizadas junto com o código (mudança de regra = mudança em `regras-de-negocio.md` no mesmo PR). |

## RNF-ESC — Escalabilidade
| Id | Tipo | Requisito |
|---|---|---|
| RNF-ESC-01 | Recomendação 🟡 | Dimensionado para a validação (dezenas a poucas centenas de usuários): um servidor, fila `database`. Caminho de evolução documentado: Redis para fila/cache/sessão, mais workers da fila `ai`. |
| RNF-ESC-02 | Recomendação 🟡 | Chamadas à IA longas nunca bloqueiam o request (plano e resumo em fila). |

## RNF-VAL — Validação de dados
Toda entrada validada no backend (Form Requests) e espelhada no front para feedback imediato; a regra do backend é a fonte da verdade. Mensagens em pt-BR. Detalhe em cada spec de feature (seção "Validações").

## RNF-OPE — Operação e infraestrutura ✅
| Id | Tipo | Requisito |
|---|---|---|
| RNF-OPE-01 | Identificado ✅ | Sem custos novos além da API de IA: todo software livre; Web Push gratuito. |
| RNF-OPE-02 | Recomendação 🟡 | Servidor do backend precisa de: PHP 8.3, MySQL 8, **cron a cada minuto** (`schedule:run`) e **processo contínuo** para `queue:work` (Supervisor/systemd). Hospedagem compartilhada sem SSH/cron não atende. |
| RNF-OPE-03 | Recomendação 🟡 | HTTPS (Let's Encrypt) em front e API — obrigatório para Web Push e cookies `Secure`. |
| RNF-OPE-04 | Recomendação 🟡 | SMTP gratuito (Gmail com senha de app ou Brevo) para recuperação de senha. |
| RNF-OPE-05 | Identificado 🔵 | Web Push no iPhone só com o app instalado na tela inicial (PWA, iOS ≥ 16.4): o front precisa de `manifest.webmanifest` e service worker, e a tela de configurações explica isso. |
