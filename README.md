# TaskMaster PHP PWA

Gerenciador de tarefas que fiz pra estudar PWA com PHP puro. Sem framework, sem banco de dados, sem dependência nenhuma — só PHP, HTML, CSS e um pouco de JS vanilla.

Os dados ficam num arquivo `tasks.json` local. Funciona offline depois da primeira visita graças ao Service Worker.

---

## Por que PHP puro?

Queria entender como montar uma aplicação completa sem depender de nada externo. Sem Laravel, sem Composer, sem npm. O desafio foi fazer algo que parecesse moderno mesmo sendo simples por baixo.

## O que dá pra fazer

- Adicionar, editar e excluir tarefas
- Marcar como concluída
- Definir categoria, prioridade (baixa/média/alta) e prazo
- Buscar por texto e filtrar por categoria ou prioridade
- Exportar e importar as tarefas em JSON
- Alternar entre dark e light mode (fica salvo no navegador)
- Instalar como app no celular ou desktop via PWA

## Estrutura

```
taskmaster/
├── index.php      # toda a lógica e interface
├── manifest.json  # configuração do PWA
├── sw.js          # service worker pra funcionar offline
├── tasks.json     # criado automaticamente, aqui ficam os dados
└── README.md
```

Deixei tudo em um arquivo só (`index.php`) de propósito — fica mais fácil de entender o fluxo inteiro sem ficar pulando entre arquivos.

## Como rodar

Precisa ter PHP 8.1 ou superior instalado.

```bash
git clone https://github.com/seu-usuario/taskmaster-php-pwa.git
cd taskmaster-php-pwa
php -S localhost:8000
```

Abre o navegador em `http://localhost:8000` e é isso.

O arquivo `tasks.json` vai ser criado automaticamente quando você adicionar a primeira tarefa. Ele tá no `.gitignore` pra não subir dados pessoais sem querer.

### Em produção

Copia os arquivos pro servidor e garante que a pasta tem permissão de escrita:

```bash
chmod 755 /caminho/da/pasta
```

O Service Worker e a instalação como PWA precisam de HTTPS pra funcionar direito. Em `localhost` funciona normalmente.

## Decisões técnicas

**PRG (Post/Redirect/Get):** todo formulário redireciona após o POST, então apertar F5 não resubmete nada.

**IDs com `uniqid()`:** usei isso no lugar de timestamp porque evita colisão quando você adiciona tarefas muito rápido.

**Comparações estritas (`===`):** o código original usava `==` pra comparar IDs, o que causava bugs com IDs em formato string. Corrigi pra `===` em todo lugar.

**Service Worker network-first:** tenta buscar do servidor primeiro, cai pro cache se estiver offline. Assim os dados ficam sempre atualizados quando há conexão.

## Licença

MIT. Faz o que quiser.