# CF7 SMTP Bridge — Documentación v2.0

## ¿Qué es este plugin?

**CF7 SMTP Bridge** es un plugin de WordPress que actúa como un puente de transporte de correo electrónico. Reemplaza la función nativa `wp_mail()` para garantizar que los correos se envíen de forma confiable y no caigan en SPAM.

### El Problema que Resuelve

WordPress usa `wp_mail()` con la configuración PHP del servidor, lo que frecuentemente resulta en:
- Correos que nunca llegan al destinatario
- Mensajes que caen en la carpeta de SPAM
- Sin registro de errores cuando un envío falla
- Sin confirmación de recepción para el usuario que llena un formulario

### La Solución

El plugin intercepta **silenciosamente** todas las llamadas a `wp_mail()` y las redirige a través de uno de dos transportes:

1. **SMTP Tradicional** — Reconfigura PHPMailer con credenciales SMTP autenticadas
2. **Gmail API (OAuth 2.0)** — Envía correos directamente a través de la REST API de Google (**recomendado**)

Cualquier plugin que use `wp_mail()` (WPForms, Contact Form 7, WooCommerce, etc.) se beneficia automáticamente **sin modificar** su front-end, diseño o lógica.

---

## Estructura de Archivos

```
cf7-smtp-bridge/
├── cf7-smtp-bridge.php                     → Archivo principal (bootstrap singleton)
├── uninstall.php                           → Limpieza al desinstalar
├── DOCUMENTACION.md                        → Este archivo
├── assets/
│   └── css/
│       └── admin.css                       → Estilos del panel de administración
├── includes/
│   ├── class-cf7-smtp-encryption.php       → Cifrado AES-256-CBC de credenciales
│   ├── class-cf7-smtp-logger.php           → Sistema de logging con rotación
│   ├── class-cf7-smtp-oauth.php            → Manejo OAuth 2.0 (tokens, refresh)
│   ├── class-cf7-smtp-settings.php         → Página de ajustes (Settings API)
│   ├── class-cf7-smtp-mailer.php           → Transporte SMTP (PHPMailer)
│   ├── class-cf7-smtp-gmail-api.php        → Transporte Gmail API (REST)
│   └── class-cf7-smtp-cf7-integration.php  → Auto-reply y CC/BCC para CF7
└── logs/
    └── index.php                           → Protección de directorio
```

---

## Cómo Funciona Cada Componente

### 1. Selector de Transporte (`transport`)

En **Ajustes > CF7 SMTP Bridge**, el campo "Mail Transport" permite elegir:

| Transporte | Cómo funciona | Cuándo usarlo |
|------------|--------------|---------------|
| **SMTP** | Hook `phpmailer_init` reconfigura PHPMailer | Servidores con puertos SMTP abiertos |
| **Gmail API** | Filtro `pre_wp_mail` intercepta `wp_mail()` completamente | **Recomendado**. No requiere puertos SMTP. Usa HTTPS |

### 2. Gmail API Transport (`CF7_SMTP_Gmail_API`)

Cuando se selecciona "Gmail API":

1. El filtro `pre_wp_mail` intercepta cada llamada a `wp_mail()`
2. Obtiene un Access Token válido vía `CF7_SMTP_OAuth` (auto-refresh)
3. Construye un mensaje MIME RFC 2822 completo con headers, body y attachments
4. Lo codifica en **base64url** (RFC 4648 §5)
5. Hace un `POST` a `https://gmail.googleapis.com/upload/gmail/v1/users/me/messages/send`
6. Registra el resultado (Message ID de Gmail o error) en el log

### 3. OAuth 2.0 (`CF7_SMTP_OAuth`)

Maneja el ciclo de vida completo de autenticación OAuth:

- **Almacenamiento seguro** de tokens en `wp_options` (cifrados)
- **Auto-refresh** del Access Token usando el Refresh Token (buffer de 5 min)
- **Intercambio de código** para el flujo de autorización inicial
- **Revocación** de tokens desde el panel de admin

### 4. SMTP Transport (`CF7_SMTP_Mailer`)

El transporte SMTP original (v1.0) sigue disponible como fallback:

- Reconfigura PHPMailer vía `phpmailer_init`
- Soporte para TLS, SSL y sin cifrado
- Debug output al logger cuando está habilitado

### 5. Cifrado de Credenciales (`CF7_SMTP_Encryption`)

Todas las credenciales sensibles (contraseñas, Client Secret, Refresh Token) se cifran con **AES-256-CBC** usando las security keys de WordPress como clave de derivación.

### 6. Integración CF7 (`CF7_SMTP_CF7_Integration`)

Funciona idénticamente con ambos transportes:

- **Auto-Reply**: Envía confirmación al campo `[your-email]` del formulario
- **CC/BCC**: Inyecta cabecera CC o BCC en el correo principal de CF7

---

## Instalación

1. Descarga o clona el repositorio
2. Sube el directorio `cf7-smtp-bridge/` a `wp-content/plugins/`
3. Activa el plugin desde **Plugins** en WordPress
4. Ve a **Ajustes > CF7 SMTP Bridge** para configurar

---

## Configuración de Gmail API (Fase 2 — Guía GCP)

### Paso 1: Crear Proyecto en Google Cloud Console

1. Ve a [Google Cloud Console](https://console.cloud.google.com/)
2. Crea un nuevo proyecto (ej: "WordPress Mail - The Everest Group")
3. Selecciona el proyecto creado

### Paso 2: Habilitar la Gmail API

1. Ve a **APIs & Services > Library**
2. Busca "Gmail API"
3. Haz clic en **Enable**

### Paso 3: Configurar Pantalla de Consentimiento OAuth

1. Ve a **APIs & Services > OAuth consent screen**
2. Selecciona **External** (o Internal si usas Google Workspace)
3. Llena los campos requeridos:
   - App name: "CF7 SMTP Bridge"
   - User support email: tu correo
   - Developer contact: tu correo
4. En **Scopes**, agrega: `https://www.googleapis.com/auth/gmail.send`
5. En **Test users**, agrega la cuenta de correo que usarás para enviar
6. Guarda y continúa

### Paso 4: Crear Credenciales OAuth 2.0

1. Ve a **APIs & Services > Credentials**
2. Clic en **+ CREATE CREDENTIALS > OAuth client ID**
3. Tipo de aplicación: **Web application**
4. Nombre: "CF7 SMTP Bridge WordPress"
5. En **Authorized redirect URIs**, agrega:
   ```
   https://tusitio.com/wp-admin/options-general.php?page=cf7-smtp-bridge
   ```
6. Clic en **Create**
7. **Copia el Client ID y Client Secret** — los necesitarás en WordPress

### Paso 5: Configurar en WordPress

1. Ve a **Ajustes > CF7 SMTP Bridge**
2. En "Mail Transport", selecciona **Gmail API**
3. Completa los campos:
   - **Client ID**: el que copiaste de GCP
   - **Client Secret**: el que copiaste de GCP
   - **Sender Email**: la cuenta de Gmail/Workspace que envía los correos
4. Haz clic en **Guardar Ajustes**
5. Aparecerá el botón **"Authorize with Google"** — haz clic
6. Completa el flujo de consentimiento de Google
7. Serás redirigido de vuelta a WordPress con los tokens guardados

### Paso 6: Verificar

1. Haz clic en **"Enviar Email de Prueba"**
2. Revisa que el email llegue a la bandeja de entrada del administrador
3. Verifica el log para confirmar: `Gmail API: Message sent successfully`

### Alternativa: Refresh Token Manual

Si prefieres obtener el Refresh Token manualmente (ej: vía Postman o OAuth Playground):

1. Ve a [Google OAuth 2.0 Playground](https://developers.google.com/oauthplayground/)
2. En el engranaje (settings), marca **"Use your own OAuth credentials"**
3. Ingresa tu Client ID y Client Secret
4. En Step 1, autoriza el scope: `https://www.googleapis.com/auth/gmail.send`
5. En Step 2, intercambia el código por tokens
6. Copia el **Refresh Token**
7. En WordPress, pégalo en el campo "Refresh Token" y guarda

---

## Configuración de Formularios CF7

Para aprovechar las funciones de Auto-Reply y CC/BCC:

### Campo de Email del Usuario

Tu formulario debe incluir un campo llamado `your-email`:

```
[email* your-email placeholder "Tu correo electrónico"]
```

El plugin también reconoce variantes: `email`, `your_email`, `user-email`, `user_email`.

### Mail-Tags en Auto-Reply

Puedes usar cualquier campo de tu formulario en el asunto y cuerpo del auto-reply:

```
Hola [your-name],

Hemos recibido tu mensaje sobre "[your-subject]".
Nuestro equipo revisará tu solicitud y te contactaremos a la brevedad.

Saludos cordiales,
The Everest Group
```

### CC/BCC Interno

Habilita CC/BCC en los ajustes para que cada envío de formulario envíe automáticamente una copia al equipo interno (configurable como CC visible o BCC oculto).

---

## Compatibilidad

- **WordPress**: 6.0+ (probado con 6.9.1)
- **PHP**: 8.2+
- **Plugins de formularios**: Contact Form 7, WPForms, Gravity Forms, o cualquier plugin que use `wp_mail()`
- **Google**: Gmail personal o Google Workspace

## Notas de Seguridad

- Las credenciales se cifran con AES-256-CBC en la base de datos
- Los tokens OAuth se almacenan con auto-renovación (no expiran permanentemente)
- Nonces verificados en todas las peticiones AJAX
- Todas las entradas sanitizadas y salidas escapadas
- El directorio de logs está protegido con `.htaccess`
- La contraseña nunca se muestra en el HTML del formulario de administración

## Licencia

GPL-2.0-or-later
