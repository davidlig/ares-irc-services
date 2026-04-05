# Debug Actions - Reglas para comandos IRCop

## Propósito

Los comandos sensibles ejecutados por IRCops deben registrar sus acciones en:
1. Un canal de debug compartido (si está configurado)
2. Un archivo de log dedicado (`ircops.log`)

## Configuración

### Variable de entorno

```env
IRCOPS_DEBUG_CHANNEL=#ircops
```

- Si está vacío o no definido, no se envían mensajes al canal
- El log en archivo siempre está activo

### Log configuration

**Archivo:** `config/packages/monolog.yaml`

```yaml
ircops_rotating:
    type:      rotating_file
    path:      '%kernel.logs_dir%/ircops.log'
    level:     info
    max_files: 26    # ~6 meses (rotación semanal)
    channels:  [ircops]
```

## Arquitectura

### Interfaz

```php
namespace App\Application\Port;

interface ServiceDebugNotifierInterface
{
    public function isConfigured(): bool;
    
    public function ensureChannelJoined(): void;
    
    public function notify(
        string $operator,
        string $command,
        string $target,
        ?string $reason = null,
        array $extra = [],
    ): void;
}
```

### Implementaciones por servicio

Cada servicio implementa `ServiceDebugNotifierInterface`:

| Servicio | Clase | Bot que envía mensajes |
|----------|-------|------------------------|
| OperServ | `OperServDebugNotifier` | OperServ |
| NickServ | `NickServDebugNotifier` | NickServ |
| ChanServ | (futuro) | ChanServ |
| MemoServ | (futuro) | MemoServ |

### Inyección en comandos

```php
public function __construct(
    private readonly ServiceDebugNotifierInterface $debugNotifier,
    // ... otras dependencias
) {}
```

## Comandos que requieren debug

Solo comandos ejecutados por IRCops requieren debug.

### OperServ (todos los comandos son IRCop-only)
- `KILL` - Desconectar usuario
- `IRCOP ADD/DEL` - Gestionar IRCops
- `ROLE ADD/DEL/MOD` - Gestionar roles y permisos
- Futuros: GLINE, KLINE, etc.

### NickServ (comandos IRCop)
- `SASET` - Modificar settings de otro usuario
- `DROP` - Eliminar nickname
- `SUSPEND` - Suspender nickname
- `UNSUSPEND` - Reactivar nickname
- `RENAME` - Forzar cambio de nick
- `FORBID` - Prohibir nickname
- `FORBIDVHOST` - Prohibir vhost
- `UNFORBID` - Desprohibir nickname
- `USERIP` - Ver IP real

### ChanServ (comandos IRCop)
- (ninguno actualmente)

## Formato de mensajes

Cada servicio envía mensajes con su propio bot como prefijo.

### En el canal de debug (con colores IRC)

**Colores:**
- Azul (`\x0302`) para nicks del operador y objetivo
- Rojo (`\x0304`) para el nombre del comando

### OperServ KILL
```
<OperServ> Operator1 ejecuta el comando KILL sobre BadUser. Motivo: Flooding channels
           Nick: BadUser | Host: ~user@192.168.1.100 | IP: 10.0.0.55
```

### NickServ SASET (con opción)
```
<NickServ> OperNick ejecuta el comando SASET sobre TargetUser. Opción: VHOST=ares.example.com
```

### NickServ SASET (PASSWORD - valor oculto)
```
<NickServ> OperNick ejecuta el comando SASET sobre TargetUser. Opción: PASSWORD
```

### NickServ SUSPEND (con duración)
```
<NickServ> OperNick ejecuta el comando SUSPEND sobre BadUser. Duración: 7d. Razón: Spam
```

### En el archivo de log (sin colores)

```
[2025-01-15T14:32:07+00:00] ircops.INFO: KILL {"operator":"Admin1","target":"BadUser","target_host":"~user@host.com","target_ip":"10.0.0.55","reason":"Flooding"} []
```

## Protección del canal

El canal de debug (`IRCOPS_DEBUG_CHANNEL`) tiene restricción de entrada:
- Solo IRCops identificados y Roots pueden entrar
- Los demás usuarios son kickeados automáticamente por ChanServ
- El mensaje de kick usa el idioma del usuario

### Flujo de protección

1. Usuario entra al canal → `UserJoinedChannelEvent`
2. `IrcopsDebugChannelProtectionSubscriber` verifica:
   a. Si el canal no es el de debug → no hacer nada
   b. Si es ChanServ → permitir
   c. Si es Root → permitir
   d. Si es IRCop identificado → permitir
   e. Si no → ChanServ kickea con mensaje traducido

## Ejemplos

### OperServ KILL
```
<OperServ> Admin1 ejecuta el comando KILL sobre BadUser. Motivo: Flooding channels
           Nick: BadUser | Host: ~user@192.168.1.100 | IP: 10.0.0.55
```

### OperServ IRCOP ADD
```
<OperServ> AdminRoot ejecuta el comando IRCOP ADD sobre NewOper. Rol: OPER
           Nick: NewOper | Host: ~oper@isp.net
```

### Proyecto futuro: NickServ DROP (IRCop-only)
```
<NickServ> OperNick ejecuta el comando DROP sobre OldAccount. Motivo: Solicitado por el usuario
           Nick: OldAccount | Host: ~user@host.com
```

## Extensión para nuevos servicios

Para añadir debug notifier a un nuevo servicio:

1. Crear `src/Infrastructure/<Service>/Service/<Service>DebugNotifier.php`
2. Implementar `ServiceDebugNotifierInterface`
3. Inyectar el logger del canal `ircops` (`@monolog.logger.ircops`) y el bot del servicio
4. Registrar en `ServiceDebugNotifierRegistry` con el tag `app.debug_notifier` en `services.yaml`
5. Añadir traducciones en `translations/<service>.en.yaml` y `.es.yaml`

## Traducciones

Claves de traducción en cada servicio:

```yaml
# operserv.en.yaml / operserv.es.yaml
debug:
  action_message: "%operator% executes command %command% on %target%. Reason: %reason%"
  action_info: "Nick: %nick% | Host: %host% | IP: %ip%"
```

```yaml
# nickserv.en.yaml / nickserv.es.yaml
debug:
  action_message: "%operator% executes command %command% on %target%. %reason%"
  action_with_option: "%operator% executes command %command% on %target%. Option: %option%. %reason%"
  action_with_value: "%operator% executes command %command% on %target%. Option: %option%=%value%. %reason%"
  action_duration: "%operator% executes command %command% on %target%. Duration: %duration%. %reason%"
  prefix_reason: "Reason: %reason%"
```

```yaml
# chanserv.en.yaml / chanserv.es.yaml
debug_channel:
  kick_reason: "You are not authorized to join this channel."
```