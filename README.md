# Data Seeder by Replicant

**Version 1.0.6** · Author: Replicant · Add-on ID: `Repli/Seeder` · Languages: English · Español · Türkçe

[English](#english) · [Español](#español) · [Türkçe](#türkçe)

---

## English

### What it is

Data Seeder generates test data for a RaxBoard forum: categories, forums and subforums, user groups (with banners), users, moderators, threads, replies and reactions. It is meant for developing and testing forums and add-ons.

> **Development use only.** It creates real accounts with a known password and can delete content. Never install it on a live forum.

### Requirements

- RaxBoard (developed and used against 1.9.9) and the PHP version the core requires (8.1+).
- A **super administrator** account: the panel is closed to everyone else.
- `exec()` enabled in PHP and a `php` command-line binary: generation runs as a background process.
- At least one **active reaction** in the system if you enable reactions, and **at least 2 users** to react to each other's content.
- Declared permission scopes (informational): `db_read`, `db_write`, `db_schema`, `shell`, `user_data`, `events`.

The add-on relies on the core's internal services (not a public API), so other RaxBoard versions may need adjustments.

### Install, upgrade, uninstall

Run from the project root (with Docker: `docker compose exec -u "$(id -u):$(id -g)" web php bin/raxboard ...`):

```
php bin/raxboard addon:validate addons/Repli/Seeder
php bin/raxboard addon:install  addons/Repli/Seeder
php bin/raxboard addon:upgrade  addons/Repli/Seeder
```

- The commands take the **folder path** (`addons/Repli/Seeder`), not the add-on id.
- Every upgrade re-seeds the add-on phrases, so new or changed texts reach the database. Without the upgrade, texts stay in the language they were first seeded in.
- Uninstalling removes the phrases but **keeps** the tables `repli_seeder_items` and `repli_seeder_jobs`. Purge the generated data before uninstalling.

### Using the panel

Open **Data Seeder** in the admin side menu (`/admin/repli-seeder`). The form has three cards:

| Card | Fields |
|---|---|
| Forum structure | Categories, forums per category, subforums per forum |
| Users and groups | User groups, users, moderators, username prefix, password, random seed |
| Content and reactions | Threads per forum, max replies per thread, reactions on threads and on replies (checkbox + percentage slider) |

While you type, an estimate of the entities and time is shown under the form.

### What it generates

| Entity | How it is created |
|---|---|
| User groups | Named `<Seed Group> <n> (<batch>)` (translated). The title, user title and banner text are the same name. Each gets a banner: a predefined design (rotating through the core's presets, except red and green) and a Font Awesome icon (rotating through the core's icon choices). |
| Users | Through the core registration service. Name = prefix + 3-digit counter (`seed001`), email `<name>@example.com`, timezone UTC, state *valid* (no email confirmation). Each user joins 0–2 of the generated groups as a secondary group. Existing names are skipped. |
| Categories, forums, subforums | Created through the core node service, each linked to its parent. Titles use the topic names built into the add-on. |
| Moderators | The first generated users. They join a staff group (`<Seed Moderators> (<batch>)`, green banner, gavel icon) and are assigned to 1–2 random forums or subforums each. |
| Threads and replies | Through the core thread and reply services: random lorem-ipsum text, random authors among the generated users. The number of replies of each thread is a random value between 0 and the maximum. |
| Reactions | Optional. On a chosen percentage of threads (content type `thread`) and/or replies (`post`): one reaction each, from a user other than the author, of any **active** reaction type. |

**Order:** groups → users and memberships → structure (category by category: its forums, then their subforums) → moderators → content (forum by forum, thread by thread, each thread followed by its replies) → reactions.

### Limits

| Field | Range |
|---|---|
| Categories | 0–7 |
| Forums per category | 0–10 |
| Subforums per forum | 0–2 |
| User groups | 0–20 |
| Users | 0–500 |
| Moderators | 0–50 (never more than the generated users) |
| Threads per forum | 0–50 |
| Max replies per thread | 0–30 |
| Reaction percentage | 0–100, in steps of 10 (at least 1 item if above 0) |
| Username prefix | up to 12 characters, letters, digits and `_` only |
| Password | at least 8 characters, otherwise the default is used |
| Random seed | 0 (random) up to 2147483647 |

Values outside these ranges are clamped on the server, even if the form is edited by hand. At the maximum a run creates 210 forums and subforums (plus 7 categories), 10,500 threads and about 157,000 replies on average (up to 315,000).

### Rules and conditions

- **One generation at a time.** While one is running, no other can start and the global purge is blocked.
- **Users = 0:** content is authored by existing valid users (up to 200). Those threads are still tracked and removed by the purge.
- **Moderators** need at least one generated user and one forum, otherwise none are assigned.
- **Reactions** are skipped (with a message) when the system has no active reaction or there are fewer than 2 possible authors.
- **Core rules still apply.** Flood, spam and moderation rules can reject content or hold it for review; those items are counted as errors on the progress bars.
- **Anti-flood is suspended** (`flood_check_thread`, `flood_check_post` set to 0) during a run and restored when it ends. The original values are stored in the database, so they are also restored if the process dies.
- **Errors:** up to 30 are kept per run; 5 are shown live and 15 in the summary. Technical details that come from the core are shown as they are.

### Progress, cancelling and the background process

- Starting a generation launches a PHP process in the background (`nohup php bin/worker.php <job>`). **Closing the page does not stop it.**
- While one runs, **every URL of the add-on shows the progress page** (one bar per entity type, elapsed time and remaining-time estimate, updated every 1.5 seconds). It reloads itself when the run finishes.
- **Cancel** stops the run and keeps what was created. **Cancel and purge this batch** stops it and deletes everything created in that batch, in the background. Both ask for confirmation and take effect once the current operation finishes (usually within a second or two).
- If the process stops sending signs of life for 90 seconds (container restarted, process killed), the run is marked as *interrupted* and the anti-flood values are restored.
- PHP errors of the process go to the core log (`data/logs/raxboard-php-*.log`).

### Estimate and warning

The time is estimated from the number of entities and an initial cost per entity (an approximation, not measured on your machine). If it exceeds **60 seconds**, you first see a warning with the breakdown and must confirm twice (the warning page and a confirmation dialog). During the run, the remaining time is recalculated from the real speed.

### Purge

**Purge generated data** deletes everything the add-on created **and everything inside the generated forums, including threads and replies added by other users**. Order: reactions → threads (with their replies) → moderator assignments → users (with their content) → forums and categories → groups.

- It is best-effort: what fails stays registered so it can be retried, and the errors are listed.
- It runs inside the web request, so a very large purge can take a while.
- Threads moved out of a generated forum are not removed, unless the add-on created them.
- After a clean global purge, the generation history is cleared.

The **Last generation** card appears only while the data of that batch still exists (failed runs are always shown, for their errors).

### Random seed

The seed drives every random choice (groups of each user, moderators, texts, number of replies, reactions). Using the same seed with the same values repeats the same choices, but the data is not guaranteed to be identical: existing usernames, core rules and the state of the database also matter.

### Languages

Texts live in `phrases/en.php`, `phrases/es.php` and `phrases/tr.php` and are seeded for each installed language whose code matches (`en`, `es`, `tr`, or `es-*` and so on). The names of the generated groups use the language of the administrator who starts the generation. Not translated: the menu item name ("Data Seeder"), durations (`min`, `s`, `h`) and technical error details.

### Data kept by the add-on

| Table | Content |
|---|---|
| `repli_seeder_items` | Every generated entity (groups, users, nodes, moderator assignments, threads, reactions), per batch. |
| `repli_seeder_jobs` | State, progress and errors of each generation. Created automatically on first use. |

### Known limitations

- Needs `exec()` and a `php` binary; without them the add-on cannot start a generation.
- The purge is not cancellable and runs synchronously.
- The initial time estimate is approximate.
- It uses the core's internal services; a core update may require changes.

### File layout

```
addon.json            manifest (version, scopes, phrases)
Setup.php             install / upgrade / uninstall steps
ServiceProvider.php   routes and admin menu
routes/               admin.php (panel), public.php (empty)
bin/worker.php        background process
src/Controller/       SeederController
src/Service/          SeederService, JobRepository, JobProgress
templates/            repli_seeder/index.php
phrases/              en.php, es.php, tr.php
```

---

## Español

### Qué es

Data Seeder genera datos de prueba para un foro RaxBoard: categorías, foros y subforos, grupos de usuarios (con banner), usuarios, moderadores, hilos, respuestas y reacciones. Está pensado para desarrollar y probar foros y addons.

> **Solo para desarrollo.** Crea cuentas reales con una contraseña conocida y puede eliminar contenido. No lo instales nunca en un foro en producción.

### Requisitos

- RaxBoard (desarrollado y usado con la 1.9.9) y la versión de PHP que exige el core (8.1+).
- Una cuenta de **super administrador**: el panel está cerrado para los demás.
- `exec()` habilitado en PHP y un binario `php` de línea de comandos: la generación corre como proceso en segundo plano.
- Al menos una **reacción activa** en el sistema si activas las reacciones, y **al menos 2 usuarios** para que reaccionen al contenido de otros.
- Permisos declarados (informativos): `db_read`, `db_write`, `db_schema`, `shell`, `user_data`, `events`.

El addon se apoya en servicios internos del core (no en una API pública), por lo que otras versiones de RaxBoard pueden requerir ajustes.

### Instalar, actualizar, desinstalar

Desde la raíz del proyecto (con Docker: `docker compose exec -u "$(id -u):$(id -g)" web php bin/raxboard ...`):

```
php bin/raxboard addon:validate addons/Repli/Seeder
php bin/raxboard addon:install  addons/Repli/Seeder
php bin/raxboard addon:upgrade  addons/Repli/Seeder
```

- Los comandos reciben la **ruta de la carpeta** (`addons/Repli/Seeder`), no el id del addon.
- Cada upgrade vuelve a sembrar las frases del addon, para que los textos nuevos o cambiados lleguen a la base de datos. Sin el upgrade, los textos siguen en el idioma en que se sembraron la primera vez.
- Al desinstalar se eliminan las frases, pero **se conservan** las tablas `repli_seeder_items` y `repli_seeder_jobs`. Purga los datos generados antes de desinstalar.

### Uso del panel

Abre **Data Seeder** en el menú lateral del admin (`/admin/repli-seeder`). El formulario tiene tres tarjetas:

| Tarjeta | Campos |
|---|---|
| Estructura del foro | Categorías, foros por categoría, subforos por foro |
| Usuarios y grupos | Grupos de usuarios, usuarios, moderadores, prefijo de usuario, contraseña, semilla aleatoria |
| Contenido y reacciones | Hilos por foro, máximo de respuestas por hilo, reacciones en hilos y en respuestas (casilla + deslizador de porcentaje) |

Mientras escribes, debajo del formulario se muestra una estimación de entidades y tiempo.

### Qué genera

| Entidad | Cómo se crea |
|---|---|
| Grupos de usuarios | Nombre `<Grupo Seed> <n> (<lote>)` (traducido). El título, el título de usuario y el texto del banner son el mismo nombre. Cada uno lleva banner: un diseño predefinido (rotando entre los del core, salvo rojo y verde) y un ícono Font Awesome (rotando entre los que ofrece el core). |
| Usuarios | Mediante el servicio de registro del core. Nombre = prefijo + contador de 3 dígitos (`seed001`), correo `<nombre>@example.com`, zona horaria UTC, estado *válido* (sin confirmación de correo). Cada usuario entra a 0–2 de los grupos generados como grupo secundario. Los nombres ya existentes se omiten. |
| Categorías, foros, subforos | Mediante el servicio de nodos del core, cada uno enlazado a su padre. Los títulos usan los temas incluidos en el addon. |
| Moderadores | Los primeros usuarios generados. Entran a un grupo de staff (`<Moderadores Seed> (<lote>)`, banner verde, ícono de mazo) y se asignan a 1–2 foros o subforos al azar cada uno. |
| Hilos y respuestas | Mediante los servicios de hilos y respuestas del core: texto lorem ipsum aleatorio y autores al azar entre los usuarios generados. La cantidad de respuestas de cada hilo es un valor al azar entre 0 y el máximo. |
| Reacciones | Opcional. Sobre un porcentaje de hilos (tipo de contenido `thread`) y/o de respuestas (`post`): una reacción cada uno, de un usuario distinto al autor y de cualquier tipo de reacción **activa**. |

**Orden:** grupos → usuarios y membresías → estructura (categoría por categoría: sus foros y luego los subforos de cada uno) → moderadores → contenido (foro por foro, hilo por hilo, cada hilo seguido de sus respuestas) → reacciones.

### Límites

| Campo | Rango |
|---|---|
| Categorías | 0–7 |
| Foros por categoría | 0–10 |
| Subforos por foro | 0–2 |
| Grupos de usuarios | 0–20 |
| Usuarios | 0–500 |
| Moderadores | 0–50 (nunca más que los usuarios generados) |
| Hilos por foro | 0–50 |
| Máximo de respuestas por hilo | 0–30 |
| Porcentaje de reacciones | 0–100, en saltos de 10 (al menos 1 elemento si es mayor que 0) |
| Prefijo de usuario | hasta 12 caracteres, solo letras, dígitos y `_` |
| Contraseña | al menos 8 caracteres; si no, se usa la predeterminada |
| Semilla aleatoria | 0 (aleatoria) hasta 2147483647 |

Los valores fuera de estos rangos se recortan en el servidor, aunque se edite el formulario a mano. Con todo al máximo, una generación crea 210 foros y subforos (más 7 categorías), 10.500 hilos y unas 157.000 respuestas en promedio (hasta 315.000).

### Reglas y condiciones

- **Una generación a la vez.** Mientras hay una en curso no se puede iniciar otra y la purga global queda bloqueada.
- **Usuarios = 0:** el contenido lo escriben usuarios válidos ya existentes (hasta 200). Esos hilos igualmente quedan registrados y la purga los elimina.
- **Los moderadores** necesitan al menos un usuario generado y un foro; si no, no se asigna ninguno.
- **Las reacciones** se omiten (con un mensaje) si el sistema no tiene reacciones activas o hay menos de 2 posibles autores.
- **Las reglas del core siguen vigentes.** El flood, el spam y la moderación pueden rechazar contenido o dejarlo en revisión; esos elementos se cuentan como errores en las barras de progreso.
- **El anti-flood se suspende** (`flood_check_thread` y `flood_check_post` en 0) durante la generación y se restaura al terminar. Los valores originales se guardan en la base de datos, así que también se restauran si el proceso muere.
- **Errores:** se guardan hasta 30 por generación; se muestran 5 en vivo y 15 en el resumen. Los detalles técnicos que vienen del core se muestran tal cual.

### Avance, cancelación y proceso en segundo plano

- Al iniciar una generación se lanza un proceso PHP en segundo plano (`nohup php bin/worker.php <trabajo>`). **Cerrar la página no lo detiene.**
- Mientras corre, **todas las URLs del addon muestran la página de avance** (una barra por tipo de entidad, tiempo transcurrido y estimación del tiempo restante, actualizados cada 1,5 segundos). Se recarga sola al terminar.
- **Cancelar** detiene la generación y conserva lo creado. **Cancelar y purgar este lote** la detiene y elimina todo lo creado en ese lote, en segundo plano. Ambos piden confirmación y surten efecto cuando termina la operación en curso (normalmente en uno o dos segundos).
- Si el proceso deja de dar señales de vida durante 90 segundos (contenedor reiniciado, proceso terminado), la generación se marca como *interrumpida* y se restauran los valores anti-flood.
- Los errores de PHP del proceso van al log del core (`data/logs/raxboard-php-*.log`).

### Estimación y advertencia

El tiempo se estima a partir de la cantidad de entidades y de un costo inicial por entidad (una aproximación, no medida en tu equipo). Si supera los **60 segundos**, primero ves una advertencia con el desglose y debes confirmar dos veces (la página de advertencia y un diálogo de confirmación). Durante la generación, el tiempo restante se recalcula con la velocidad real.

### Purga

**Purgar datos generados** elimina todo lo que creó el addon **y todo lo que haya dentro de los foros generados, incluidos hilos y respuestas agregados por otros usuarios**. Orden: reacciones → hilos (con sus respuestas) → asignaciones de moderador → usuarios (con su contenido) → foros y categorías → grupos.

- Es de mejor esfuerzo: lo que falla queda registrado para reintentar y los errores se listan.
- Corre dentro de la petición web, así que una purga muy grande puede tardar.
- Los hilos movidos fuera de un foro generado no se eliminan, salvo que los haya creado el addon.
- Tras una purga global limpia, se borra el historial de generaciones.

La tarjeta **Última generación** aparece solo mientras los datos de ese lote siguen existiendo (las generaciones fallidas se muestran siempre, por sus errores).

### Semilla aleatoria

La semilla gobierna toda decisión aleatoria (grupos de cada usuario, moderadores, textos, cantidad de respuestas, reacciones). Usar la misma semilla con los mismos valores repite las mismas decisiones, pero no garantiza datos idénticos: también influyen los nombres de usuario ya existentes, las reglas del core y el estado de la base de datos.

### Idiomas

Los textos están en `phrases/en.php`, `phrases/es.php` y `phrases/tr.php`, y se siembran para cada idioma instalado cuyo código coincida (`en`, `es`, `tr`, o `es-*` y similares). Los nombres de los grupos generados usan el idioma del administrador que inicia la generación. No se traducen: el nombre de la opción del menú ("Data Seeder"), las duraciones (`min`, `s`, `h`) y el detalle de los errores técnicos.

### Datos que guarda el addon

| Tabla | Contenido |
|---|---|
| `repli_seeder_items` | Cada entidad generada (grupos, usuarios, nodos, asignaciones de moderador, hilos, reacciones), por lote. |
| `repli_seeder_jobs` | Estado, avance y errores de cada generación. Se crea sola la primera vez que se usa. |

### Limitaciones conocidas

- Necesita `exec()` y un binario `php`; sin ellos el addon no puede iniciar una generación.
- La purga no se puede cancelar y corre de forma síncrona.
- La estimación inicial de tiempo es aproximada.
- Usa servicios internos del core; una actualización del core puede requerir cambios.

### Estructura de archivos

```
addon.json            manifiesto (versión, permisos, frases)
Setup.php             pasos de instalación / actualización / desinstalación
ServiceProvider.php   rutas y menú de admin
routes/               admin.php (panel), public.php (vacío)
bin/worker.php        proceso en segundo plano
src/Controller/       SeederController
src/Service/          SeederService, JobRepository, JobProgress
templates/            repli_seeder/index.php
phrases/              en.php, es.php, tr.php
```

---

## Türkçe

### Nedir?

Data Seeder, bir RaxBoard forumu için test verileri oluşturur: kategoriler, forumlar ve alt forumlar, kullanıcı grupları (bannerlı), kullanıcılar, moderatörler, konular, yanıtlar ve tepkiler. Forumları ve eklentileri geliştirmek ve test etmek için tasarlanmıştır.

> **Yalnızca geliştirme amaçlıdır.** Bilinen bir parolayla gerçek hesaplar oluşturur ve içerik silebilir. Canlı bir foruma asla kurmayın.

### Gereksinimler

- RaxBoard (1.9.9 ile geliştirildi ve kullanıldı) ve çekirdeğin gerektirdiği PHP sürümü (8.1+).
- Bir **süper yönetici** hesabı: panel diğer herkese kapalıdır.
- PHP'de etkin `exec()` ve komut satırı için bir `php` ikilisi: oluşturma işlemi arka planda bir süreç olarak çalışır.
- Tepkileri etkinleştirirseniz sistemde en az bir **etkin tepki**, ve başkalarının içeriğine tepki verilebilmesi için **en az 2 kullanıcı**.
- Bildirilen izin kapsamları (bilgilendirme amaçlı): `db_read`, `db_write`, `db_schema`, `shell`, `user_data`, `events`.

Eklenti, çekirdeğin dahili hizmetlerine (herkese açık bir API'ye değil) dayanır; bu nedenle diğer RaxBoard sürümlerinde uyarlama gerekebilir.

### Kurulum, güncelleme, kaldırma

Proje kök dizininden çalıştırın (Docker ile: `docker compose exec -u "$(id -u):$(id -g)" web php bin/raxboard ...`):

```
php bin/raxboard addon:validate addons/Repli/Seeder
php bin/raxboard addon:install  addons/Repli/Seeder
php bin/raxboard addon:upgrade  addons/Repli/Seeder
```

- Komutlar eklenti kimliğini değil, **klasör yolunu** (`addons/Repli/Seeder`) alır.
- Her güncelleme eklentinin ifadelerini yeniden ekler; böylece yeni veya değişen metinler veritabanına ulaşır. Güncelleme yapılmazsa metinler ilk eklendikleri dilde kalır.
- Kaldırma işlemi ifadeleri siler, ancak `repli_seeder_items` ve `repli_seeder_jobs` tablolarını **korur**. Kaldırmadan önce oluşturulan verileri temizleyin.

### Panelin kullanımı

Yönetici yan menüsünden **Data Seeder**'ı açın (`/admin/repli-seeder`). Formda üç kart vardır:

| Kart | Alanlar |
|---|---|
| Forum yapısı | Kategoriler, kategori başına forum, forum başına alt forum |
| Kullanıcılar ve gruplar | Kullanıcı grupları, kullanıcılar, moderatörler, kullanıcı adı öneki, parola, rastgele tohum |
| İçerik ve tepkiler | Forum başına konu, konu başına en fazla yanıt, konulara ve yanıtlara tepki (onay kutusu + yüzde kaydırıcısı) |

Yazarken formun altında öğe sayısı ve süre tahmini gösterilir.

### Neler oluşturur?

| Varlık | Nasıl oluşturulur |
|---|---|
| Kullanıcı grupları | Ad: `<Seed Grubu> <n> (<parti>)` (çevrilmiş). Başlık, kullanıcı unvanı ve banner metni aynı addır. Her grup bir banner alır: önceden tanımlı bir tasarım (çekirdeğin hazır tasarımları arasında dönüşümlü, kırmızı ve yeşil hariç) ve bir Font Awesome simgesi (çekirdeğin sunduğu simgeler arasında dönüşümlü). |
| Kullanıcılar | Çekirdeğin kayıt hizmetiyle. Ad = önek + 3 haneli sayaç (`seed001`), e-posta `<ad>@example.com`, saat dilimi UTC, durum *geçerli* (e-posta onayı yok). Her kullanıcı, oluşturulan gruplardan 0–2 tanesine ikincil grup olarak katılır. Zaten var olan adlar atlanır. |
| Kategoriler, forumlar, alt forumlar | Çekirdeğin düğüm hizmetiyle, her biri üst öğesine bağlı olarak. Başlıklar eklentiye gömülü konu adlarını kullanır. |
| Moderatörler | Oluşturulan ilk kullanıcılar. Bir yönetici grubuna (`<Seed Moderatörleri> (<parti>)`, yeşil banner, tokmak simgesi) katılırlar ve her biri rastgele 1–2 foruma veya alt foruma atanır. |
| Konular ve yanıtlar | Çekirdeğin konu ve yanıt hizmetleriyle: rastgele lorem ipsum metni, oluşturulan kullanıcılar arasından rastgele yazarlar. Her konunun yanıt sayısı 0 ile üst sınır arasında rastgele bir değerdir. |
| Tepkiler | İsteğe bağlı. Seçilen yüzdedeki konulara (içerik türü `thread`) ve/veya yanıtlara (`post`): her birine yazarından farklı bir kullanıcıdan, **etkin** herhangi bir tepki türüyle bir tepki. |

**Sıra:** gruplar → kullanıcılar ve üyelikler → yapı (kategori kategori: forumları, ardından her birinin alt forumları) → moderatörler → içerik (forum forum, konu konu; her konuyu yanıtları izler) → tepkiler.

### Sınırlar

| Alan | Aralık |
|---|---|
| Kategoriler | 0–7 |
| Kategori başına forum | 0–10 |
| Forum başına alt forum | 0–2 |
| Kullanıcı grupları | 0–20 |
| Kullanıcılar | 0–500 |
| Moderatörler | 0–50 (oluşturulan kullanıcı sayısını asla aşmaz) |
| Forum başına konu | 0–50 |
| Konu başına en fazla yanıt | 0–30 |
| Tepki yüzdesi | 0–100, 10'ar adımla (0'dan büyükse en az 1 öğe) |
| Kullanıcı adı öneki | en fazla 12 karakter, yalnızca harf, rakam ve `_` |
| Parola | en az 8 karakter; aksi halde varsayılan kullanılır |
| Rastgele tohum | 0 (rastgele) ile 2147483647 arası |

Bu aralıkların dışındaki değerler, form elle düzenlense bile sunucuda kırpılır. Her şey en yüksek değerdeyken bir çalıştırma 210 forum ve alt forum (artı 7 kategori), 10.500 konu ve ortalama yaklaşık 157.000 yanıt oluşturur (en fazla 315.000).

### Kurallar ve koşullar

- **Aynı anda tek oluşturma.** Biri çalışırken başka biri başlatılamaz ve genel temizleme engellenir.
- **Kullanıcı = 0:** içerik, mevcut geçerli kullanıcılar (en fazla 200) tarafından yazılır. Bu konular yine de kayıt altındadır ve temizleme onları siler.
- **Moderatörler** için en az bir oluşturulmuş kullanıcı ve bir forum gerekir; aksi halde hiçbiri atanmaz.
- **Tepkiler**, sistemde etkin tepki yoksa veya olası yazar sayısı 2'den azsa (bir iletiyle) atlanır.
- **Çekirdek kuralları geçerliliğini korur.** Flood, spam ve moderasyon kuralları içeriği reddedebilir veya incelemeye alabilir; bu öğeler ilerleme çubuklarında hata olarak sayılır.
- **Flood koruması askıya alınır** (`flood_check_thread` ve `flood_check_post` 0 yapılır) ve işlem bitince geri yüklenir. Özgün değerler veritabanında saklanır; bu nedenle süreç ölse bile geri yüklenir.
- **Hatalar:** her çalıştırmada en fazla 30 hata saklanır; 5'i canlı, 15'i özette gösterilir. Çekirdekten gelen teknik ayrıntılar olduğu gibi gösterilir.

### İlerleme, iptal ve arka plan süreci

- Bir oluşturma başlatıldığında arka planda bir PHP süreci çalışır (`nohup php bin/worker.php <iş>`). **Sayfayı kapatmak süreci durdurmaz.**
- Bir işlem çalışırken **eklentinin tüm adresleri ilerleme sayfasını gösterir** (varlık türü başına bir çubuk, geçen süre ve kalan süre tahmini; her 1,5 saniyede güncellenir). İşlem bitince sayfa kendini yeniler.
- **İptal et**, işlemi durdurur ve o ana kadar oluşturulanları korur. **İptal et ve bu partiyi temizle** işlemi durdurur ve o partide oluşturulan her şeyi arka planda siler. İkisi de onay ister ve süren işlem bittiğinde etki eder (genellikle bir ya da iki saniye içinde).
- Süreç 90 saniye boyunca yaşam belirtisi vermezse (kapsayıcı yeniden başlatıldı, süreç sonlandırıldı), işlem *kesintiye uğradı* olarak işaretlenir ve flood koruması değerleri geri yüklenir.
- Sürecin PHP hataları çekirdek günlüğüne yazılır (`data/logs/raxboard-php-*.log`).

### Tahmin ve uyarı

Süre, öğe sayısından ve varlık başına başlangıç maliyetinden tahmin edilir (bir yaklaşımdır, makinenizde ölçülmemiştir). **60 saniyeyi** aşarsa önce dökümüyle birlikte bir uyarı görürsünüz ve iki kez onaylamanız gerekir (uyarı sayfası ve bir onay penceresi). Çalışma sırasında kalan süre gerçek hıza göre yeniden hesaplanır.

### Temizleme

**Oluşturulan verileri temizle**, eklentinin oluşturduğu her şeyi **ve oluşturulan forumların içindeki her şeyi, diğer kullanıcıların eklediği konular ve yanıtlar dahil**, siler. Sıra: tepkiler → konular (yanıtlarıyla) → moderatör atamaları → kullanıcılar (içerikleriyle) → forumlar ve kategoriler → gruplar.

- En iyi çaba esasıyla çalışır: başarısız olanlar yeniden denenebilmesi için kayıtlı kalır ve hatalar listelenir.
- Web isteği içinde çalışır; bu nedenle çok büyük bir temizleme zaman alabilir.
- Oluşturulan bir forumun dışına taşınan konular, eklenti oluşturmadıkça silinmez.
- Temiz bir genel temizlemeden sonra oluşturma geçmişi silinir.

**Son oluşturma** kartı, yalnızca o partinin verileri hâlâ varken görünür (başarısız çalıştırmalar hataları nedeniyle her zaman gösterilir).

### Rastgele tohum

Tohum, her rastgele seçimi belirler (her kullanıcının grupları, moderatörler, metinler, yanıt sayısı, tepkiler). Aynı değerlerle aynı tohumu kullanmak aynı seçimleri tekrarlar, ancak verilerin özdeş olacağı garanti edilmez: mevcut kullanıcı adları, çekirdek kuralları ve veritabanının durumu da etkiler.

### Diller

Metinler `phrases/en.php`, `phrases/es.php` ve `phrases/tr.php` dosyalarındadır ve kodu eşleşen her yüklü dil için eklenir (`en`, `es`, `tr` veya `es-*` gibi). Oluşturulan grupların adları, oluşturmayı başlatan yöneticinin dilini kullanır. Çevrilmeyenler: menü öğesinin adı ("Data Seeder"), süreler (`min`, `s`, `h`) ve teknik hata ayrıntıları.

### Eklentinin sakladığı veriler

| Tablo | İçerik |
|---|---|
| `repli_seeder_items` | Oluşturulan her varlık (gruplar, kullanıcılar, düğümler, moderatör atamaları, konular, tepkiler), parti başına. |
| `repli_seeder_jobs` | Her oluşturmanın durumu, ilerlemesi ve hataları. İlk kullanımda otomatik oluşturulur. |

### Bilinen sınırlamalar

- `exec()` ve bir `php` ikilisi gerekir; bunlar yoksa eklenti bir oluşturma başlatamaz.
- Temizleme iptal edilemez ve eşzamanlı çalışır.
- Başlangıç süre tahmini yaklaşıktır.
- Çekirdeğin dahili hizmetlerini kullanır; bir çekirdek güncellemesi değişiklik gerektirebilir.

### Dosya yapısı

```
addon.json            bildirim dosyası (sürüm, izinler, ifadeler)
Setup.php             kurulum / güncelleme / kaldırma adımları
ServiceProvider.php   rotalar ve yönetici menüsü
routes/               admin.php (panel), public.php (boş)
bin/worker.php        arka plan süreci
src/Controller/       SeederController
src/Service/          SeederService, JobRepository, JobProgress
templates/            repli_seeder/index.php
phrases/              en.php, es.php, tr.php
```
