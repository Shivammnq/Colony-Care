</div>
</div>

<script>
let notifOpen = false;
function toggleNotif(e) {
    e.stopPropagation();
    notifOpen = !notifOpen;
    document.getElementById('notifDropdown').classList.toggle('open', notifOpen);
    if (notifOpen) fetchNotifications();
}
document.addEventListener('click', function(e) {
    const nd = document.getElementById('notifDropdown');
    const nb = document.getElementById('notifBell');
    if (nd && !nd.contains(e.target) && e.target !== nb && !nb.contains(e.target)) {
        notifOpen = false;
        nd.classList.remove('open');
    }
});
const typeIcon = {
    payment:      ['fa fa-credit-card','ni-payment'],
    complaint:    ['fa fa-triangle-exclamation','ni-complaint'],
    approval:     ['fa fa-circle-check','ni-approval'],
    rejection:    ['fa fa-circle-xmark','ni-rejection'],
    verification: ['fa fa-shield-check','ni-verification'],
};
function timeAgo(dateStr) {
    const diff = Math.floor((Date.now() - new Date(dateStr)) / 1000);
    if (diff < 60)    return 'Just now';
    if (diff < 3600)  return Math.floor(diff/60) + 'm ago';
    if (diff < 86400) return Math.floor(diff/3600) + 'h ago';
    return Math.floor(diff/86400) + 'd ago';
}
function fetchNotifications() {
    fetch('/notification_handler.php?action=fetch')
        .then(r=>r.json())
        .then(data=>{
            const dot = document.getElementById('notifDot');
            const list = document.getElementById('notifList');
            if (data.count > 0) { dot.classList.add('show'); } else { dot.classList.remove('show'); }
            if (!data.items || data.items.length === 0) {
                list.innerHTML = '<div class="notif-empty"><i class="fa fa-bell-slash" style="display:block;font-size:1.4rem;margin-bottom:6px;opacity:.4"></i>No notifications yet</div>';
                return;
            }
            list.innerHTML = data.items.map(n => {
                const [ico, cls] = typeIcon[n.type] || ['fa fa-bell','ni-approval'];
                return `<a class="notif-item ${n.is_read==0?'unread':''}" href="${n.link||'#'}" onclick="markRead(${n.id},event,'${n.link||'#'}')">
                    <div class="notif-icon ${cls}"><i class="${ico}"></i></div>
                    <div class="notif-text">
                        <div class="notif-msg">${n.message}</div>
                        <div class="notif-time">${timeAgo(n.created_at)}</div>
                    </div>
                </a>`;
            }).join('');
        }).catch(()=>{});
}
function markRead(id, e, link) {
    e.preventDefault();
    fetch('/notification_handler.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:`action=mark_read&id=${id}`
    }).then(()=>{
        if (link && link !== '#') window.location.href = link;
        else fetchNotifications();
    });
}
function markAllRead() {
    fetch('/notification_handler.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=mark_read&id=0'
    }).then(()=>fetchNotifications());
}
fetchNotifications();
setInterval(fetchNotifications, 30000);

// ── Header search ────────────────────────────────────────────
let searchTimer = null;
const sbSearchInput = document.getElementById('sbSearchInput');
const sbSearchResults = document.getElementById('sbSearchResults');
if (sbSearchInput) {
    sbSearchInput.addEventListener('input', function(){
        clearTimeout(searchTimer);
        const q = this.value.trim();
        if (q.length < 2) { sbSearchResults.classList.remove('open'); return; }
        searchTimer = setTimeout(() => runSearch(q), 300);
    });
    document.addEventListener('click', function(e){
        if (!sbSearchInput.contains(e.target) && !sbSearchResults.contains(e.target)) {
            sbSearchResults.classList.remove('open');
        }
    });
}

function runSearch(q) {
    fetch('/super_admin_search.php?q=' + encodeURIComponent(q))
        .then(r => r.json())
        .then(data => {
            if (!data.success) { sbSearchResults.innerHTML = '<div class="sr-empty">Search failed.</div>'; sbSearchResults.classList.add('open'); return; }

            const hasResults = data.societies.length || data.residents.length || data.listings.length;
            if (!hasResults) {
                sbSearchResults.innerHTML = '<div class="sr-empty">No matches for "' + q + '"</div>';
                sbSearchResults.classList.add('open');
                return;
            }

            let html = '';
            if (data.societies.length) {
                html += '<div class="sr-group-label">Societies</div>';
                data.societies.forEach(s => {
                    html += `<a class="sr-item" href="/super_admin_society.php?id=${s.id}">${s.society_name}<div class="sr-sub">${s.city || ''}${s.city && s.state ? ', ' : ''}${s.state || ''}</div></a>`;
                });
            }
            if (data.residents.length) {
                html += '<div class="sr-group-label">Residents</div>';
                data.residents.forEach(r => {
                    html += `<a class="sr-item" href="/super_admin_residents.php">${r.name}<div class="sr-sub">${r.email} · ${r.society_name || 'No society'}</div></a>`;
                });
            }
            if (data.listings.length) {
                html += '<div class="sr-group-label">Sale &amp; Rent Listings</div>';
                data.listings.forEach(l => {
                    const unitLabel = (l.block ? l.block + '-' : '') + l.unit;
                    html += `<a class="sr-item" href="/super_admin_saleandrent.php">${unitLabel} — ${l.listing_type}<div class="sr-sub">${l.society_name || ''} · ₹${Number(l.price).toLocaleString('en-IN')}</div></a>`;
                });
            }
            sbSearchResults.innerHTML = html;
            sbSearchResults.classList.add('open');
        })
        .catch(() => {
            sbSearchResults.innerHTML = '<div class="sr-empty">Search failed.</div>';
            sbSearchResults.classList.add('open');
        });
}
</script>

</body>
</html>