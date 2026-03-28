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

interface DebugActionPort
{
    public function isConfigured(): bool;
    
    public function ensureChannelJoined(): void;
    
    public function log(
        string $operator,
        string $command,
        string $target,
        ?string $targetHost = null,
        ?string $targetIp = null,
        ?string $reason = null,
        array $extra = [],
    ): void;
}
```

### Implementaciones por servicio

Cada servicio implementa `DebugActionPort`:

| Servicio | Clase | Bot que envía mensajes |
|----------|-------|------------------------|
| OperServ | `OperServDebugAction` | OperServ |
| NickServ | (futuro) `NickServDebugAction` | NickServ |
| ChanServ | (futuro) `ChanServDebugAction` | ChanServ |

### Inyección en comandos

```php
public function __construct(
    private readonly DebugActionPort $debug,
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
- (ninguno actualmente)

### ChanServ (comandos IRCop)
- (ninguno actualmente)

## Formato de mensajes

### En el canal de debug (con colores IRC)

**Colores:**
- Azul (`\x0302`) para nicks del operador y objetivo
- Rojo (`\x0304`) para el nombre del comando

```
<OperServ> Operator1 ejecuta el comando KILL sobre BadUser. Motivo: Flooding channels
           Nick: BadUser | Host: ~user@192.168.1.100 | IP: 10.0.0.55
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

Para añadir debug actions a un nuevo servicio:

1. Crear `src/Infrastructure/<Service>/Service/<Service>DebugAction.php`
2. Implementar `DebugActionPort`
3. Inyectar el logger del canal `ircops` (`@monolog.logger.ircops`)
4. Configurar en `services.yaml`
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
# chanserv.en.yaml / chanserv.es.yaml
debug_channel:
  kick_reason: "You are not authorized to join this channel."
```