<p align="center">
  <img width="80" height="80" src="logo.png"/>
  <h3 align="center"><a href="https://whispphp.com">Whisp</a></h3>
  <h4 align="center">Your pure* PHP SSH server & the best way to build SSH based TUIs</h4>
</p>

> [!NOTE]
> Quick example **sign our guestbook**
> ```bash
> ssh guestbook@whisp.fyi
> ```

Explore the docs at **[WhispPHP.com »](https://whispphp.com)**

# Installation

```bash
composer require whispphp/whisp
```


# Usage

Run the server on the port & IP you'd like, with the apps you want to make available.

```php
use Whisp\Server;

$server = new Server(port: 2222);

// Available apps - each is its own script forked
$server->run([
    'default' => __DIR__.'/examples/howdy.php',
    'guestbook' => __DIR__.'/examples/guestbook.php',
]);

// $server->run('full-path/howdy.php'); // Pass just one path if you'd like to only support 1 default script
```

Once running you can test with:

**Default app**
```bash
ssh localhost -p2222
```

**Guestbook app**
```bash
ssh guestbook@localhost -p2222
```
or
```bash
ssh localhost -p2222 -t guestbook
```

Each connection is forked to its own process and runs independently.

## Environment variables available to each app
Each app is provided environment variables from the SSH client which are available in the $_ENV or $_SERVER array.

| Variable | Description | Notes |
|----------|-------------|------|
| WHISP_APP | The name of the app being requested | |
| WHISP_CLIENT_IP | The IP address of the connecting client | |
| WHISP_TTY | The TTY information for the connection | e.g. /dev/ttys06 |
| WHISP_USERNAME | The username used in the SSH connection | Empty string if not provided |

## How to change existing server's ssh server to a diff. port

## How to setup systemd so whisp server always running on port 22


## Setting requested app
Two options for loading the correct app:
1. `ssh app@server` - we use the 'username' here as the app name if it matches an available app.
  - Much cleaner, but means if you need the username for auth you can't define the app this way
2. `ssh server -t app` - request an interactive shell (`-t`) with the requested `app`


# Future
- [ ] Add chacha support so we don't require AES-256 on the server (`sodium_crypto_aead_aes256gcm_is_available`)
- [ ] Enable hooking into userauth
- [ ] Simplify WinSize and TerminalInfo

---


# Examples
**Sign the Whisp Guestbook**
([See the code](https://github.com/WhispPHP/whisp/blob/main/examples/guestbook.php))
```bash
ssh guestbook@whisp.fyi
```

**Play the Dinorun game**
[See the code](https://github.com/WhispPHP/whisp/blob/main/examples/dinorun.php)
```bash
ssh dinorun@whisp.fyi
```

**Sunrise/sunset**
[See the code](https://github.com/WhispPHP/whisp/blob/main/examples/daylight.php)
```bash
ssh daylight@whisp.fyi
```

**Find your closest World Heritage Sites**
```bash
ssh elec@whisp.fyi
```

---

## Support & Credits

This was developed by Ashley Hindle. If you like it, please star it, share it, and let me know!

- [Bluesky](https://bsky.app/profile/ashleyhindle.com)
- [Twitter](https://twitter.com/ashleyhindle)
- Website [https://ashleyhindle.com](https://ashleyhindle.com)
