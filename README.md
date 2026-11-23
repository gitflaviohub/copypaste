# CP · Copiar & Colar

Página PHP para cPanel (`flavioleite.pt/cp`) com uma lista pública de textos. Cada texto tem um botão **Copiar** e um **QR code** com o próprio texto. Criar, editar e apagar textos exige um PIN de 6 dígitos.

## Instalação no cPanel

1. Entre no cPanel e abra o "Gestor de Ficheiros" (File Manager).
2. Abra a pasta public_html (a raiz de flavioleite.pt).
3. Comprima a pasta `cp` deste repositório num `cp.zip`, clique em "Carregar" (Upload) e envie esse ficheiro.
4. Volte ao public_html, clique com o botão direito em `cp.zip` e escolha "Extrair" (Extract).
   Deve ficar com a pasta public_html/cp/ com index.php, qrcode.js e a pasta dados/.
   (Pode apagar o `cp.zip` depois.)
5. Abra logo https://flavioleite.pt/cp e defina o seu PIN de 6 dígitos.
   Importante: até definir o PIN, qualquer pessoa que abra a página o poderia definir,
   por isso faça este passo logo a seguir a extrair.

## Como usar

- Qualquer pessoa pode ver os textos, copiá-los com o botão "Copiar" e abrir o "QR code".
- Para criar, editar ou apagar, carregue em "Editar" (canto superior direito) e introduza o PIN.
- A sessão de edição termina ao carregar em "Sair da edição" ou após 1 hora sem uso.
- O PIN pode ser mudado em "Mudar PIN", no fundo da página, em modo de edição.
- Após 5 PINs errados, esse endereço IP fica bloqueado 15 minutos.

## Esqueci-me do PIN

- No Gestor de Ficheiros, apague o ficheiro public_html/cp/dados/config.php.
  Ao abrir a página de novo, ela pede um PIN novo. Os textos não se perdem.

## Cópia de segurança

- Todos os textos estão em public_html/cp/dados/textos.php. Basta guardar esse ficheiro.

## Notas

- Requer PHP 7.4 ou superior (o cPanel permite escolher em "Select PHP Version").
- Se aparecer um erro de gravação, confirme que a pasta dados/ tem permissões 755.
- O QR code contém o próprio texto e aceita até cerca de 2900 caracteres simples.
- O botão Copiar funciona melhor com HTTPS (ative o SSL gratuito em "SSL/TLS Status" se ainda não tiver).
