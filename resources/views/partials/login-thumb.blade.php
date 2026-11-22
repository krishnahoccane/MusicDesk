{{-- Miniature of a sign-in design for pickers ($design). Decorative. --}}
<span class="ld-login-thumb" aria-hidden="true">
    @switch($design)
        @case('split')
            <i class="t-glow" style="left:-10%;top:-20%;width:45%;height:60%;background:var(--ld-accent)"></i>
            <i style="left:6%;bottom:30%;width:4%;height:16%;background:var(--ld-accent)"></i>
            <i style="left:12%;bottom:30%;width:4%;height:24%;background:var(--ld-accent-2)"></i>
            <i style="left:18%;bottom:30%;width:4%;height:12%;background:var(--ld-accent)"></i>
            <i class="t-line" style="left:6%;bottom:18%;width:34%"></i>
            <i class="t-panel" style="left:48%;top:0;width:52%;height:100%;border-radius:0"></i>
            <i style="left:58%;top:30%;width:30%;height:5px;background:#c9c9d8"></i>
            <i style="left:58%;top:44%;width:32%;height:8px;background:#e4e4ee"></i>
            <i style="left:58%;top:58%;width:32%;height:8px;background:#e4e4ee"></i>
            <i class="t-btn" style="left:58%;top:72%;width:32%"></i>
            @break
        @case('cover')
            <i style="inset:0;background:linear-gradient(135deg,var(--ld-accent),#0b0b12 60%,var(--ld-accent-2));opacity:.75"></i>
            <i class="t-line" style="left:7%;top:58%;width:34%"></i>
            <i class="t-line" style="left:7%;top:68%;width:24%;opacity:.5"></i>
            <i class="t-card" style="left:56%;top:16%;width:36%;height:68%"></i>
            <i class="t-btn" style="left:61%;top:68%;width:26%"></i>
            @break
        @case('studio')
            <i style="left:0;top:0;width:46%;height:100%;background:#11111a"></i>
            <i style="left:8%;top:32%;width:28%;height:5px;background:rgba(255,255,255,.4)"></i>
            <i style="left:8%;top:46%;width:30%;height:8px;background:rgba(255,255,255,.12)"></i>
            <i style="left:8%;top:60%;width:30%;height:8px;background:rgba(255,255,255,.12)"></i>
            <i class="t-btn" style="left:8%;top:74%;width:30%"></i>
            <i class="t-glow" style="right:-10%;top:-10%;width:45%;height:60%;background:var(--ld-accent-2)"></i>
            <i class="t-card" style="left:56%;top:40%;width:34%;height:22%"></i>
            <i style="left:59%;top:44%;width:8%;height:14%;background:linear-gradient(135deg,var(--ld-accent),var(--ld-accent-2))"></i>
            @break
        @case('minimal')
            <i style="inset:0;background:#f4f4f8"></i>
            <i style="left:46%;top:18%;width:8%;height:12%;background:linear-gradient(135deg,var(--ld-accent),var(--ld-accent-2))"></i>
            <i style="left:34%;top:40%;width:32%;height:8px;background:#e1e1ec"></i>
            <i style="left:34%;top:54%;width:32%;height:8px;background:#e1e1ec"></i>
            <i class="t-btn" style="left:34%;top:68%;width:32%"></i>
            @break
        @default
            <i class="t-glow" style="left:10%;top:-20%;width:40%;height:60%;background:var(--ld-accent)"></i>
            <i class="t-glow" style="right:5%;bottom:-25%;width:40%;height:60%;background:var(--ld-accent-2)"></i>
            <i class="t-line" style="left:35%;top:14%;width:30%"></i>
            <i class="t-card" style="left:32%;top:26%;width:36%;height:62%"></i>
            <i style="left:37%;top:42%;width:26%;height:7px;background:rgba(255,255,255,.14)"></i>
            <i style="left:37%;top:56%;width:26%;height:7px;background:rgba(255,255,255,.14)"></i>
            <i class="t-btn" style="left:37%;top:72%;width:26%"></i>
    @endswitch
</span>
