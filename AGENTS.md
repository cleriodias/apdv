IMPORTANTE
- Totos os campos de data devem abrir o calendario para preenchimento
- Todas as datas apresentadas decem ter o formato DD/MM/AA
- Sempre que alguma alteraçao for solicitada mostrar um plano detalhado e aguardar o ok
- Nunca executar dumps, a menos que seja solicitado e confirmado com o ok
- Todas as interacoes devem ser em portugues
- Nunca sugerir para o humano modificar algo no codigo; se algo precisa ser mudado voce deve mostre o plano detalhado e aguardde o ok para fazer.
- Sempre liste os arquivos criados/modificados de forma a ser clicaveis e o humano possa ver o codigo no VS code.
- Na criacao de tabelas usar a liguagem portugues Brasil e o prefixo "tb" + um sequencial. "_", e o nome, exemplo: (tb1_compras_canceladas), sempre verifique as tabelas para nao duplicar o sequencial.

FUNÇOES
- A prioridade é crescente quanto menor o numero maior o previlegio, com exeçao do BOSS que é 7 e é o mais alto
    0 = MASTER
    1 = GERENTE
    2 = SUB-GERENTE
    3 = CAIXA
    4 = LANCHONETE
    5 = FUNCIONARIO
    6 = CLIENTE


SINCRONIZAR
- Este app é uma copia do app : "C:\xampp\htdocs\apec-rodrigo" com algumas particularidades
1. tem login
2. Apenas usuarios Master podem logar
3. So vê as lojas vinculadas a matriz(relatorios, usuario, lojas, vendas, etc...)
4. Sempre que for solicitado um sincronizar ajuste as particularidades pois, nao é uma copia fiel.

PADRAO VISUAL OBRIGATORIO:
- Botoes e badges de loja devem sempre usar as cores primarias predefinidas centralmente no codigo.
- Botoes e badges de funcao devem sempre usar as cores primarias predefinidas centralmente no codigo.
- Badge de nome de usuario deve sempre usar texto preto com fundo branco.
- A paleta padrao oficial do sistema para fonte, botoes, badges e estados visuais e: `Default`, `Primary`, `Secondary`, `Info`, `Success`, `Warning`, `Error`, `Dark` e `Light`.
- Sempre reutilizar esses estilos/tokens visuais centrais do sistema e nao criar variacoes paralelas quando ja existir uma opcao padrao adequada.

