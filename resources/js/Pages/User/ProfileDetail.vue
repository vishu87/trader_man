<script setup>
import { computed } from 'vue';

const props = defineProps({
    profile: Object,
});

const initials = computed(() => {
    const parts = (props.profile?.name || '').trim().split(' ').filter(Boolean);
    if (parts.length >= 2) return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
    return parts[0]?.[0]?.toUpperCase() ?? '?';
});

const memberSince = computed(() => {
    if (!props.profile?.created_at) return '—';
    return new Date(props.profile.created_at).toLocaleDateString('en-IN', {
        day: 'numeric', month: 'long', year: 'numeric',
    });
});
</script>

<template>
    <Content>
        <div class="pd-wrap">

            <!-- Avatar card -->
            <div class="pd-avatar-card">
                <div class="pd-avatar">
                    <img v-if="profile.profile_pic" :src="'/' + profile.profile_pic" :alt="profile.name" class="pd-avatar-img" />
                    <span v-else class="pd-avatar-initials">{{ initials }}</span>
                </div>
                <div class="pd-name">{{ profile.name }}</div>
                <div class="pd-username">@{{ profile.username }}</div>
                <div class="pd-since">Member since {{ memberSince }}</div>
            </div>

            <!-- Details card -->
            <div class="pd-details-card">
                <div class="pd-section-title">Account Details</div>
                <div class="pd-rows">
                    <div class="pd-row">
                        <div class="pd-row-icon"><i class="bi bi-envelope-fill"></i></div>
                        <div class="pd-row-body">
                            <div class="pd-row-label">Email</div>
                            <div class="pd-row-value">{{ profile.email || '—' }}</div>
                        </div>
                    </div>
                    <div class="pd-row">
                        <div class="pd-row-icon"><i class="bi bi-phone-fill"></i></div>
                        <div class="pd-row-body">
                            <div class="pd-row-label">Mobile</div>
                            <div class="pd-row-value">{{ profile.mobile || '—' }}</div>
                        </div>
                    </div>
                    <div class="pd-row">
                        <div class="pd-row-icon"><i class="bi bi-person-badge-fill"></i></div>
                        <div class="pd-row-body">
                            <div class="pd-row-label">Username</div>
                            <div class="pd-row-value mono">{{ profile.username || '—' }}</div>
                        </div>
                    </div>
                </div>

                <div class="pd-section-title" style="margin-top:28px">Quick Actions</div>
                <div class="pd-actions">
                    <a href="/password-update" class="pd-action-btn">
                        <i class="bi bi-shield-lock"></i>
                        Change Password
                        <i class="bi bi-chevron-right ms-auto"></i>
                    </a>
                </div>
            </div>

        </div>
    </Content>
</template>

<style scoped>
.pd-wrap {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 20px;
    padding: 40px 16px 60px;
}

/* ── Avatar card ── */
.pd-avatar-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 36px 40px 28px;
    width: 100%;
    max-width: 440px;
    display: flex;
    flex-direction: column;
    align-items: center;
    box-shadow: 0 4px 24px rgba(0,0,0,.06);
}

.pd-avatar {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: #dbeafe;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    margin-bottom: 14px;
    border: 3px solid #bfdbfe;
}

.pd-avatar-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.pd-avatar-initials {
    font-size: 1.8rem;
    font-weight: 700;
    color: #2563eb;
    font-family: 'IBM Plex Mono', monospace;
}

.pd-name {
    font-size: 1.15rem;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 2px;
}

.pd-username {
    font-size: .875rem;
    color: #64748b;
    font-family: 'IBM Plex Mono', monospace;
    margin-bottom: 6px;
}

.pd-since {
    font-size: .78rem;
    color: #94a3b8;
}

/* ── Details card ── */
.pd-details-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 28px 32px;
    width: 100%;
    max-width: 440px;
    box-shadow: 0 4px 24px rgba(0,0,0,.06);
}

.pd-section-title {
    font-size: .72rem;
    font-weight: 700;
    letter-spacing: .07em;
    text-transform: uppercase;
    color: #94a3b8;
    margin-bottom: 14px;
}

.pd-rows {
    display: flex;
    flex-direction: column;
    gap: 0;
    border: 1px solid #f1f5f9;
    border-radius: 10px;
    overflow: hidden;
}

.pd-row {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 16px;
    border-bottom: 1px solid #f1f5f9;
}
.pd-row:last-child { border-bottom: none; }

.pd-row-icon {
    width: 34px;
    height: 34px;
    background: #f8fafc;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #3b82f6;
    font-size: 1rem;
    flex-shrink: 0;
}

.pd-row-label {
    font-size: .75rem;
    color: #94a3b8;
    margin-bottom: 1px;
}

.pd-row-value {
    font-size: .9rem;
    color: #0f172a;
    font-weight: 500;
}

/* ── Actions ── */
.pd-actions {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.pd-action-btn {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    color: #374151;
    font-size: .875rem;
    font-weight: 500;
    text-decoration: none;
    transition: background .15s, border-color .15s;
}
.pd-action-btn:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
    color: #0f172a;
}
.pd-action-btn > .bi:first-child {
    font-size: 1.05rem;
    color: #3b82f6;
}

@media (prefers-color-scheme: dark) {
    .pd-avatar-card,
    .pd-details-card {
        background: #1e293b;
        border-color: #334155;
    }
    .pd-name    { color: #f1f5f9; }
    .pd-rows    { border-color: #334155; }
    .pd-row     { border-color: #334155; }
    .pd-row-icon { background: #0f172a; }
    .pd-row-value { color: #e2e8f0; }
    .pd-action-btn { border-color: #334155; color: #cbd5e1; }
    .pd-action-btn:hover { background: #0f172a; border-color: #475569; color: #f1f5f9; }
}
</style>
