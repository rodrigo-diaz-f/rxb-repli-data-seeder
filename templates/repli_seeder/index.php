<rax:extends template="admin/layout" />

<rax:set var="pageTitle" expr="$p('repli_seeder_page_title', 'Data Seeder')" />
<rax:set var="adminPage" value="addons" />
<rax:set var="view" expr="$view ?? 'form'" />
<rax:set var="__t" expr="fn($k, $d, $r = []) => strtr($p('repli_seeder_' . $k, $d), $r)" />

<p class="text-sm text-[var(--muted-foreground)] mb-5">
    {{ $__t('intro', 'Generate test data for development. Everything created here is tracked so it can be purged.') }}
</p>

<script type="application/json" id="rs-i18n">{!! $i18nJson !!}</script>

<rax:if is="$view === 'progress'">

    <div class="card mb-5">
        <div class="card-header">
            <span><i class="fas fa-gears mr-1"></i> <span id="rs-status">{{ $__t('status_running', 'Generating data') }}</span></span>
            <span class="text-xs text-dim" id="rs-meta"></span>
        </div>
        <div class="card-body space-y-4">
            <p class="text-xs text-dim">
                {{ $__t('progress_note', 'Batch {batch}. The process runs in the background: you can close this page and come back, and any URL of this addon will show the progress while it runs.', ['{batch}' => $job['batch']]) }}
            </p>
            <div id="rs-bars" class="space-y-3"></div>
            <div id="rs-errors"></div>
        </div>
    </div>

    <div class="flex flex-wrap gap-3" id="rs-cancel-forms">
        <form method="POST" action="/admin/repli-seeder/cancel"
            data-confirm="{{ $__t('cancel_confirm', 'Cancel the generation? What has been created so far is kept.') }}">
            <rax:csrf />
            <input type="hidden" name="mode" value="keep">
            <button type="submit" class="btn btn-ghost">
                <i class="fas fa-ban mr-1"></i> {{ $__t('cancel', 'Cancel') }}
            </button>
        </form>
        <form method="POST" action="/admin/repli-seeder/cancel"
            data-confirm="{{ $__t('cancel_purge_confirm', 'Cancel the generation and delete everything created in this batch?') }}">
            <rax:csrf />
            <input type="hidden" name="mode" value="purge">
            <button type="submit" class="btn btn-danger">
                <i class="fas fa-trash mr-1"></i> {{ $__t('cancel_purge', 'Cancel and purge this batch') }}
            </button>
        </form>
    </div>

    <script{!! !empty($cspNonce) ? ' nonce="' . htmlspecialchars($cspNonce, ENT_QUOTES) . '"' : '' !!}>
    (function () {
        var T = JSON.parse(document.getElementById('rs-i18n').textContent);
        var statusUrl = '/admin/repli-seeder/status';
        var barsBox = document.getElementById('rs-bars');
        var rows = {};

        function fmt(s) {
            if (s === null || s === undefined) { return '...'; }
            s = Math.max(0, Math.round(s));
            if (s < 60) { return s + ' s'; }
            var m = Math.floor(s / 60);
            if (m < 60) { return m + ' min ' + (s % 60) + ' s'; }
            return Math.floor(m / 60) + ' h ' + (m % 60) + ' min';
        }

        function makeRow(key) {
            var wrap = document.createElement('div');
            var top = document.createElement('div');
            top.style.cssText = 'display:flex;justify-content:space-between;font-size:.8rem;margin-bottom:.25rem';
            var label = document.createElement('span');
            var count = document.createElement('span');
            var track = document.createElement('div');
            track.style.cssText = 'height:8px;border-radius:4px;overflow:hidden;background:var(--color-border-dark, #334155)';
            var fill = document.createElement('div');
            fill.style.cssText = 'height:100%;width:0;background:var(--color-primary, #3b82f6);transition:width .4s ease';
            track.appendChild(fill);
            top.appendChild(label);
            top.appendChild(count);
            wrap.appendChild(top);
            wrap.appendChild(track);
            barsBox.appendChild(wrap);
            rows[key] = { wrap: wrap, label: label, count: count, fill: fill };
            return rows[key];
        }

        function render(d) {
            document.getElementById('rs-status').textContent = T.status[d.status] || d.status;
            var meta = T.elapsed + ' ' + fmt(d.elapsed);
            if (d.status === 'running' && d.eta !== null) { meta += ' | ' + T.remaining + ' ~' + fmt(d.eta); }
            document.getElementById('rs-meta').textContent = meta;

            (d.bars || []).forEach(function (b) {
                var row = rows[b.key] || makeRow(b.key);
                var processed = b.done + b.failed;
                var pct = b.total > 0 ? Math.min(100, Math.round(processed * 100 / b.total)) : 0;
                row.wrap.style.display = (b.total > 0 || processed > 0) ? '' : 'none';
                row.label.textContent = (T.bars && T.bars[b.key]) || b.label;
                row.count.textContent = b.done + ' / ' + b.total + (b.failed > 0 ? ' (' + b.failed + ' ' + T.errorsWord + ')' : '') + '  ' + pct + '%';
                row.fill.style.width = pct + '%';
            });

            var forms = document.getElementById('rs-cancel-forms');
            if (forms) { forms.style.display = (d.status === 'cancelling' || d.status === 'purging') ? 'none' : ''; }

            var box = document.getElementById('rs-errors');
            box.textContent = '';
            if (d.errors && d.errors.length) {
                var alertBox = document.createElement('div');
                alertBox.className = 'alert alert-warning';
                var ul = document.createElement('ul');
                ul.className = 'text-sm';
                d.errors.slice(0, 5).forEach(function (m) {
                    var li = document.createElement('li');
                    li.textContent = m;
                    ul.appendChild(li);
                });
                alertBox.appendChild(ul);
                box.appendChild(alertBox);
            }
        }

        function poll() {
            fetch(statusUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    render(d);
                    if (d.finished) {
                        setTimeout(function () { location.reload(); }, 800);
                    } else {
                        setTimeout(poll, 1500);
                    }
                })
                .catch(function () { setTimeout(poll, 3000); });
        }
        poll();
    })();
    </script>

<rax:elseif is="$view === 'warning'" />

    <div class="alert alert-warning mb-4">
        <i class="fas fa-triangle-exclamation mr-1"></i>
        {{ $__t('warn_text', 'This generation is estimated to take about {duration}, above the warning threshold of {limit}. It is an approximation based on initial per-entity costs, so the real time may differ.', ['{duration}' => $duration, '{limit}' => $limit]) }}
    </div>

    <div class="card mb-5">
        <div class="card-header"><span><i class="fas fa-list mr-1"></i> {{ $__t('warn_created_title', 'What would be created') }}</span></div>
        <div class="card-body">
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <rax:foreach loop="$estimate['rows'] as $row">
                    <div class="bg-[var(--color-dark-surface)] border border-[var(--color-border-dark)] rounded-xl p-4">
                        <div class="text-xl font-bold text-white">{{ $row['total'] }}</div>
                        <div class="text-[0.6rem] text-dim uppercase tracking-wider">{{ $barLabels[$row['key']] ?? $row['label'] }}</div>
                    </div>
                </rax:foreach>
            </div>
            <p class="text-xs text-dim mt-3">{{ $__t('warn_expected_note', 'Replies, group memberships and moderator assignments are expected values (averages).') }}</p>
        </div>
    </div>

    <div class="flex flex-wrap gap-3">
        <a href="/admin/repli-seeder" class="btn btn-ghost"><i class="fas fa-arrow-left mr-1"></i> {{ $__t('back', 'Back to the form') }}</a>
        <form method="POST" action="/admin/repli-seeder/run"
            data-confirm="{{ $__t('start_confirm', 'Start a generation estimated to take about {duration}? It will run in the background and can be cancelled.', ['{duration}' => $duration]) }}">
            <rax:csrf />
            <rax:foreach loop="$hidden as $name => $value">
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            </rax:foreach>
            <button type="submit" class="btn btn-primary"><i class="fas fa-play mr-1"></i> {{ $__t('start_anyway', 'Start anyway') }}</button>
        </form>
    </div>

<rax:else />

    <rax:set var="__thrOn" expr="!empty($form['enable_thread_reactions']) ? 'true' : 'false'" />
    <rax:set var="__repOn" expr="!empty($form['enable_reply_reactions']) ? 'true' : 'false'" />

    <rax:if is="!empty($startError)">
        <div class="alert alert-danger mb-4"><i class="fas fa-circle-exclamation mr-1"></i> {{ $startError }}</div>
    </rax:if>

    <rax:if is="!empty($last) && !empty($last['finished'])">
        <div class="card mb-5">
            <div class="card-header">
                <span><i class="fas fa-clock-rotate-left mr-1"></i> {{ $__t('last_title', 'Last generation: {status}', ['{status}' => $lastLabel]) }}</span>
                <span class="text-xs text-dim">{{ $__t('last_meta', 'Batch {batch} | {seconds} s', ['{batch}' => $last['batch'], '{seconds}' => $last['elapsed']]) }}<rax:if is="!empty($last['rng_seed'])">{{ $__t('last_seed', ' | seed {seed}', ['{seed}' => $last['rng_seed']]) }}</rax:if></span>
            </div>
            <div class="card-body space-y-4">
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <rax:foreach loop="$last['bars'] as $bar">
                        <rax:if is="$bar['done'] > 0 || $bar['failed'] > 0">
                            <div class="bg-[var(--color-dark-surface)] border border-[var(--color-border-dark)] rounded-xl p-4">
                                <div class="text-xl font-bold text-white">{{ $bar['done'] }}</div>
                                <div class="text-[0.6rem] text-dim uppercase tracking-wider">{{ $barLabels[$bar['key']] ?? $bar['label'] }}</div>
                                <rax:if is="$bar['failed'] > 0"><div class="text-xs text-red-400">{{ $bar['failed'] }} {{ $__t('errors_word', 'errors') }}</div></rax:if>
                            </div>
                        </rax:if>
                    </rax:foreach>
                </div>
                <rax:if is="!empty($last['errors'])">
                    <div class="alert alert-warning">
                        <strong>{{ $__t('errors_first', 'Errors (first {count})', ['{count}' => count($last['errors'])]) }}</strong>
                        <ul class="text-sm mt-2">
                            <rax:foreach loop="$last['errors'] as $err">
                                <li>{{ $err }}</li>
                            </rax:foreach>
                        </ul>
                    </div>
                </rax:if>
            </div>
        </div>
    </rax:if>

    <rax:if is="!empty($purgeResult)">
        <div class="alert alert-success mb-4">
            <i class="fas fa-circle-check mr-1"></i>
            {{ $__t('purge_done', 'Purge finished: {count} items deleted.', ['{count}' => $purgeResult['deleted']]) }}
        </div>
        <rax:if is="!empty($purgeResult['errors'])">
            <div class="alert alert-warning mb-4">
                <i class="fas fa-triangle-exclamation mr-1"></i>
                <strong>{{ $__t('errors_first', 'Errors (first {count})', ['{count}' => count($purgeResult['errors'])]) }}</strong>
                <ul class="text-sm mt-2">
                    <rax:foreach loop="$purgeResult['errors'] as $err">
                        <li>{{ $err }}</li>
                    </rax:foreach>
                </ul>
            </div>
        </rax:if>
    </rax:if>

    <form method="POST" action="/admin/repli-seeder/run" class="space-y-6" id="rs-form"
        x-data="{ thrOn: {!! $__thrOn !!}, thrPct: {{ (int) $form['thread_reaction_pct'] }}, repOn: {!! $__repOn !!}, repPct: {{ (int) $form['reply_reaction_pct'] }} }">
        <rax:csrf />

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="card">
                <div class="card-header"><span><i class="fas fa-sitemap mr-1"></i> {{ $__t('card_structure', 'Forum structure') }}</span></div>
                <div class="card-body space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="form-group">
                            <label class="form-label">{{ $__t('f_categories', 'Categories') }}</label>
                            <input type="number" name="categories" min="0" max="7" value="{{ $form['categories'] }}" class="form-control text-sm">
                        </div>
                        <div class="form-group">
                            <label class="form-label">{{ $__t('f_forums_per_category', 'Forums per category') }}</label>
                            <input type="number" name="forums_per_category" min="0" max="10" value="{{ $form['forums_per_category'] }}" class="form-control text-sm">
                        </div>
                        <div class="form-group">
                            <label class="form-label">{{ $__t('f_subforums', 'Subforums per forum') }}</label>
                            <input type="number" name="subforums_per_forum" min="0" max="2" value="{{ $form['subforums_per_forum'] }}" class="form-control text-sm">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><span><i class="fas fa-users mr-1"></i> {{ $__t('card_users', 'Users and groups') }}</span></div>
                <div class="card-body space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="form-group">
                            <label class="form-label">{{ $__t('f_groups', 'User groups') }}</label>
                            <input type="number" name="groups" min="0" max="20" value="{{ $form['groups'] }}" class="form-control text-sm">
                        </div>
                        <div class="form-group">
                            <label class="form-label">{{ $__t('f_users', 'Users') }}</label>
                            <input type="number" name="users" min="0" max="500" value="{{ $form['users'] }}" class="form-control text-sm">
                        </div>
                        <div class="form-group">
                            <label class="form-label">{{ $__t('f_moderators', 'Moderators') }}</label>
                            <input type="number" name="moderators" min="0" max="50" value="{{ $form['moderators'] }}" class="form-control text-sm">
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="form-group">
                            <label class="form-label">{{ $__t('f_prefix', 'Username prefix') }}</label>
                            <input type="text" name="username_prefix" maxlength="12" value="{{ $form['username_prefix'] }}" class="form-control text-sm">
                        </div>
                        <div class="form-group">
                            <label class="form-label">{{ $__t('f_password', 'Password for generated users') }}</label>
                            <input type="text" name="password" value="{{ $form['password'] }}" class="form-control text-sm">
                        </div>
                        <div class="form-group">
                            <label class="form-label">{{ $__t('f_seed', 'Random seed (0 = random)') }}</label>
                            <input type="number" name="rng_seed" min="0" value="{{ $form['rng_seed'] }}" class="form-control text-sm">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><span><i class="fas fa-comments mr-1"></i> {{ $__t('card_content', 'Content and reactions') }}</span></div>
            <div class="card-body space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-group space-y-2">
                        <label class="form-label">{{ $__t('f_threads', 'Threads per forum') }}</label>
                        <input type="number" name="threads_per_forum" min="0" max="50" value="{{ $form['threads_per_forum'] }}" class="form-control text-sm">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" name="enable_thread_reactions" value="1" class="form-checkbox" x-model="thrOn" {!! !empty($form['enable_thread_reactions']) ? 'checked' : '' !!}>
                            <span class="text-sm">{{ $__t('f_react_threads', 'Add reactions to threads') }}</span>
                        </label>
                        <div class="flex items-center gap-3" :style="thrOn ? '' : 'opacity:.5'">
                            <span class="text-xs text-dim">{{ $__t('f_pct_threads', '% of threads') }}</span>
                            <input type="range" name="thread_reaction_pct" min="0" max="100" step="10" class="w-full" x-model.number="thrPct" :disabled="!thrOn" {!! empty($form['enable_thread_reactions']) ? 'disabled' : '' !!}>
                            <output class="text-sm" x-text="thrPct + '%'">{{ (int) $form['thread_reaction_pct'] }}%</output>
                        </div>
                    </div>
                    <div class="form-group space-y-2">
                        <label class="form-label">{{ $__t('f_replies', 'Max replies per thread') }}</label>
                        <input type="number" name="max_replies" min="0" max="30" value="{{ $form['max_replies'] }}" class="form-control text-sm">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" name="enable_reply_reactions" value="1" class="form-checkbox" x-model="repOn" {!! !empty($form['enable_reply_reactions']) ? 'checked' : '' !!}>
                            <span class="text-sm">{{ $__t('f_react_replies', 'Add reactions to replies') }}</span>
                        </label>
                        <div class="flex items-center gap-3" :style="repOn ? '' : 'opacity:.5'">
                            <span class="text-xs text-dim">{{ $__t('f_pct_replies', '% of replies') }}</span>
                            <input type="range" name="reply_reaction_pct" min="0" max="100" step="10" class="w-full" x-model.number="repPct" :disabled="!repOn" {!! empty($form['enable_reply_reactions']) ? 'disabled' : '' !!}>
                            <output class="text-sm" x-text="repPct + '%'">{{ (int) $form['reply_reaction_pct'] }}%</output>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-4">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-seedling mr-1"></i> {{ $__t('generate', 'Generate data') }}
            </button>
            <span class="text-xs text-dim" id="rs-estimate"></span>
        </div>
    </form>

    <form method="POST" action="/admin/repli-seeder/purge" class="mt-6"
        data-confirm="{{ $__t('purge_confirm_all', 'Delete all generated data and EVERYTHING inside the generated forums, including threads and replies added by other users?') }}">
        <rax:csrf />
        <button type="submit" class="btn btn-danger">
            <i class="fas fa-trash mr-1"></i> {{ $__t('purge', 'Purge generated data') }}
        </button>
    </form>

    <script{!! !empty($cspNonce) ? ' nonce="' . htmlspecialchars($cspNonce, ENT_QUOTES) . '"' : '' !!}>
    (function () {
        var T = JSON.parse(document.getElementById('rs-i18n').textContent);
        var form = document.getElementById('rs-form');
        var out = document.getElementById('rs-estimate');
        var timer = null;

        function refresh() {
            var params = new URLSearchParams(new FormData(form));
            fetch('/admin/repli-seeder/estimate?' + params.toString(), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    out.textContent = T.estimateLine.replace('{entities}', d.entities).replace('{duration}', d.duration) + (d.warn ? T.estimateWarn : '');
                    out.style.color = d.warn ? 'var(--color-warning, #f59e0b)' : '';
                })
                .catch(function () { out.textContent = ''; });
        }

        function schedule() {
            clearTimeout(timer);
            timer = setTimeout(refresh, 400);
        }

        form.addEventListener('input', schedule);
        form.addEventListener('change', schedule);
        refresh();
    })();
    </script>

</rax:if>
