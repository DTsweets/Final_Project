<?php
/**
 * เนื้อ Dashboard ของคณะ — ใช้ร่วมกัน dean/index.php และ officer/index.php (หน้าตาชุดเดียวกัน ตัวเลขชุดเดียวกัน)
 * การ์ดหลัก ปล่อย / ดูดกลับ / Net → ขอบเขต 1/2/3 (+ ความครบถ้วน) → แถบกิจกรรมที่คณะจัด → อันดับรายคณะ + ภาพรวมทุกปี
 * ตัวแปรที่หน้าที่ include ต้องมี: $o (dean_dash_overview), $sum, $years, $year_label, $affil_id, $affil_name, $scope_meta, $arrow, $h, $n2, $pct
 */
?>
            <!-- ── การ์ดหลัก: ปล่อย / ดูดกลับ / Net ── -->
            <div class="ad-hero">
                <section class="ad-kpi ad-k-gross oe-rise" style="--i:1;">
                    <div class="ad-kpi-top"><span class="ad-kpi-ic"><?= ic('factory', 22) ?></span><span class="ad-kpi-label">การปล่อยทั้งหมดของคณะ ปี <?= $h($year_label) ?></span></div>
                    <div class="ad-kpi-val"><b class="ad-gross" data-count="<?= $pct($sum['gross']) ?>"><?= $n2($sum['gross']) ?></b> <small>tCO₂e</small></div>
                    <div class="ad-parts">
                        <span>ดำเนินงาน <b class="ad-part-op"><?= $n2($sum['operation_total']) ?></b></span>
                        <span>กิจกรรม <b class="ad-part-ev"><?= $n2($sum['event_total']) ?></b></span>
                        <span>แบบสอบถาม <b class="ad-part-sv"><?= $n2($sum['survey_total']) ?></b></span>
                    </div>
                    <div class="ad-kpi-foot">
                        <?php if (in_array($o['compare']['state'], ['ok', 'low'], true)): ?>
                        <span class="ad-badge is-<?= $o['badge']['tone'] ?>" title="เทียบกับปี <?= $h($o['compare']['prev_year']['year']) ?>"><?= $h($o['badge']['text']) ?> จากปี <?= $h($o['compare']['prev_year']['year']) ?></span>
                        <?php else: ?>
                        <span class="ad-badge is-flat">ยังไม่มีข้อมูลปีก่อนให้เทียบ</span>
                        <?php endif; ?>
                        <button type="button" class="oe-btn ad-btn" data-ad-open="items">ดูรายการทั้งหมด <?= $arrow ?></button>
                    </div>
                </section>

                <section class="ad-kpi ad-k-removal oe-rise" style="--i:2;">
                    <div class="ad-kpi-top"><span class="ad-kpi-ic"><?= ic('leaf', 22) ?></span><span class="ad-kpi-label">การดูดกลับ</span></div>
                    <div class="ad-kpi-val"><b class="ad-removal" data-count="<?= $pct($sum['removal']) ?>"><?= $n2($sum['removal']) ?></b> <small>tCO₂e</small></div>
                    <div class="ad-parts"><span>จากกิจกรรมที่คณะจัด</span></div>
                    <div class="ad-kpi-foot">
                        <?php $rm_events = count(array_filter($o['event_groups'], fn($g) => $g['removal'] > 0)); ?>
                        <?php if ($rm_events > 0): ?>
                        <span class="ad-badge is-flat"><?= $rm_events ?> กิจกรรม</span>
                        <button type="button" class="oe-btn ad-btn" data-ad-open="removal">ดูรายการดูดกลับ <?= $arrow ?></button>
                        <?php else: ?>
                        <span class="ad-badge is-flat">ยังไม่มีกิจกรรมดูดกลับ</span>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="ad-kpi ad-k-net oe-rise" style="--i:3;">
                    <div class="ad-kpi-top"><span class="ad-kpi-ic"><?= ic('globe', 22) ?></span><span class="ad-kpi-label">สุทธิ (Net) · ติดตาม Net Zero</span></div>
                    <div class="ad-kpi-val"><b class="ad-net" data-count="<?= $pct($sum['net']) ?>"><?= $n2($sum['net']) ?></b> <small>tCO₂e</small></div>
                    <div class="ad-parts"><span>= ปล่อยทั้งหมด − ดูดกลับ</span></div>
                    <div class="ad-offset">
                        <?php $off = $o['offset']; ?>
                        <div class="ad-offset-row"><span>ดูดกลับชดเชยได้</span><b class="ad-offset-val"><?= $off === null ? '—' : ($off >= 100 ? 'ครบ 100% (Net ติดลบ)' : number_format($off, 2) . '%') ?></b></div>
                        <div class="ad-track"><span class="ad-fill ad-fill-green" style="width:<?= $pct(min((float) $off, 100)) ?>%;"></span></div>
                    </div>
                </section>
            </div>

            <!-- ── ขอบเขต 1/2/3 + ความครบถ้วนของการกรอก ── -->
            <div class="ad-scopes">
                <?php foreach ([1, 2, 3] as $s):
                    $share = $sum['gross'] > 0 ? $sum['scope'][$s] / $sum['gross'] * 100 : 0.0;
                    $m = $scope_meta[$s]; $f = $o['fill'][$s]; ?>
                <button type="button" class="ad-scope oe-rise" style="--i:<?= $s + 3 ?>;--sc:<?= $m['color'] ?>;--sc-deep:<?= $m['deep'] ?>;" data-ad-open="scope" data-scope="<?= $s ?>">
                    <span class="ad-scope-head"><span class="ad-scope-name">ขอบเขต <?= $s ?></span><span class="ad-scope-share"><?= number_format($share, 1) ?>%</span></span>
                    <span class="ad-scope-desc"><?= $m['name'] ?></span>
                    <span class="ad-scope-val"><b class="ad-scope-num" data-scope-val="<?= $s ?>" data-count="<?= $pct($sum['scope'][$s]) ?>"><?= $n2($sum['scope'][$s]) ?></b> <small>tCO₂e</small></span>
                    <span class="ad-track"><span class="ad-fill" style="width:<?= $pct($share) ?>%;"></span></span>
                    <span class="ad-scope-fill">กรอกแล้ว <b><?= $f['filled'] ?> / <?= $f['total'] ?></b> รายการ</span>
                    <span class="ad-scope-go">ดูรายการ <?= $arrow ?></span>
                </button>
                <?php endforeach; ?>
            </div>

            <?php if ($o['event_count'] > 0): ?>
            <!-- ── กิจกรรมที่คณะจัด: แถบเดียว (กิจกรรมเยอะแค่ไหนก็สูงเท่าเดิม · รายชื่องานดูในหน้าต่าง) ── -->
            <div class="ad-evbar oe-rise" style="--i:7;">
                <span class="ad-evbar-ic"><?= ic('note', 20) ?></span>
                <div class="ad-evbar-text">
                    <b>กิจกรรมที่คณะจัด <span class="ad-evbar-count"><?= $o['event_count'] ?></span> งาน · <span class="ad-evbar-total" data-count="<?= number_format($sum['event_total'], 4, '.', '') ?>" data-digits="4"><?= number_format($sum['event_total'], 4) ?></span> tCO₂e</b>
                    <small>นับรวมในยอดปล่อยทั้งหมดด้านบนแล้ว</small>
                </div>
                <button type="button" class="ad-evbar-btn" data-ad-open="events">ดูรายละเอียดกิจกรรม <?= $arrow ?></button>
            </div>
            <?php endif; ?>

            <div class="ad-grid is-even">
                <!-- ── อันดับรายคณะ/หน่วยงาน: อันดับของคณะ (ตัวใหญ่) + แถบตำแหน่ง + 3 อันดับแรก — อันดับทั้งหมดเปิดในหน้าต่าง ── -->
                <section class="oe-panel ad-rank ad-rank-card oe-rise" style="--i:8;">
                    <div class="co-panel-head">
                        <h2 class="co-h2">อันดับการปล่อยรายคณะ/หน่วยงาน <span class="co-count"><?= count($o['ranking']) ?> หน่วยงาน</span></h2>
                    </div>
                    <?php if (!$o['ranking']): ?>
                    <div class="oe-empty co-empty"><h3>ยังไม่มีข้อมูลการดำเนินงานในปีนี้</h3><p>เมื่อหน่วยงานกรอกข้อมูลแล้ว อันดับจะแสดงที่นี่</p></div>
                    <?php else: $own = $o['own_rank']; $hero = dean_dash_rank_hero($o['ranking'], $own); ?>
                    <div class="ad-rank-hero">
                        <div class="ad-rank-big"><?php if ($own): ?><b data-count="<?= $own['rank'] ?>" data-digits="0"><?= $own['rank'] ?></b><?php else: ?><b>–</b><?php endif; ?><span>/ <?= count($o['ranking']) ?></span></div>
                        <div class="ad-rank-info">
                            <strong title="<?= $h($affil_name) ?>"><?= $h($affil_name) ?></strong>
                            <?php if ($own): ?>
                            <span><b><?= $n2($own['total']) ?></b> tCO₂e · <?= number_format($own['share'], 1) ?>% ของทุกหน่วยงาน</span>
                            <small>
                                <span class="<?= $hero['diff_avg'] <= 0 ? 'is-good' : 'is-bad' ?>"><?= $hero['diff_avg'] <= 0 ? 'ต่ำกว่า' : 'สูงกว่า' ?>ค่าเฉลี่ย <?= $n2(abs($hero['diff_avg'])) ?></span>
                                · <?= $hero['below_top'] === null ? 'ปล่อยมากที่สุดของมหาวิทยาลัย' : 'น้อยกว่าอันดับ 1 อยู่ ' . number_format($hero['below_top'], 1) . '%' ?>
                            </small>
                            <?php else: ?>
                            <span>ยังไม่มีข้อมูลการดำเนินงานในปีนี้</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="ad-rank-track" aria-label="ตำแหน่งของคณะเทียบทุกหน่วยงาน">
                        <span class="ad-rank-end">ปล่อยมาก</span>
                        <div class="ad-rank-dots" style="--n:<?= count($hero['dots']) ?>;<?= $hero['own_pos'] !== null ? '--pos:' . $pct($hero['own_pos']) . '%;' : '' ?>">
                            <?php foreach ($hero['dots'] as $k => $dt): ?><i class="ad-rank-dot<?= $dt['own'] ? ' is-own' : '' ?>" style="--i:<?= $k ?>;" title="อันดับ <?= $dt['rank'] ?> · <?= $h($dt['name']) ?>"></i><?php endforeach; ?>
                            <?php if ($hero['own_pos'] !== null): ?><span class="ad-rank-pin">คณะของฉัน</span><?php endif; ?>
                        </div>
                        <span class="ad-rank-end">ปล่อยน้อย</span>
                    </div>
                    <div class="ad-rank-subh">3 อันดับแรก</div>
                    <ol class="ad-rank-list">
                        <?php foreach (array_slice($o['ranking'], 0, 3) as $k => $rk): $is_own = $rk['affil_id'] === $affil_id; ?>
                        <li class="ad-rank-item<?= $is_own ? ' is-own' : '' ?>" style="--i:<?= $k ?>;">
                            <div class="ad-rank-row is-static">
                                <span class="ad-rank-no is-top"><?= $rk['rank'] ?></span>
                                <?php if ($is_own): ?><span class="ad-rank-name" title="<?= $h($rk['name']) ?>"><span class="ad-rank-name-text"><?= $h($rk['name']) ?></span><span class="ad-own-tag">คณะของฉัน</span></span><?php else: ?><span class="ad-rank-name" title="<?= $h($rk['name']) ?>"><?= $h($rk['name']) ?></span><?php endif; ?>
                                <span class="ad-rank-bar"><span class="ad-rank-fill" style="width:<?= $pct(max($rk['bar'], 1)) ?>%;"><?php foreach ([1, 2, 3] as $s): ?><i style="width:<?= $pct($rk['scope_pct'][$s]) ?>%;background:<?= $scope_meta[$s]['color'] ?>;"></i><?php endforeach; ?></span></span>
                                <span class="ad-rank-val"><?= $n2($rk['total']) ?></span>
                                <span class="ad-rank-pct"><?= number_format($rk['share'], 1) ?>%</span>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ol>
                    <button type="button" class="oe-btn oe-btn-soft ad-rank-more" data-ad-open="ranking">ดูอันดับทั้งหมด (<?= count($o['ranking']) ?> หน่วยงาน) <?= $arrow ?></button>
                    <?php endif; ?>
                </section>

                <!-- ── ภาพรวมทุกปี: สลับ คณะของฉัน / ทั้งมหาวิทยาลัย (กราฟวาดใหม่ตามข้อมูลที่เลือก) ── -->
                <section class="oe-panel ad-side oe-rise" style="--i:9;">
                    <div class="co-panel-head ad-side-head">
                        <h2 class="co-h2">ภาพรวมทุกปี</h2>
                        <div class="ad-seg" role="group" aria-label="ข้อมูลของกราฟ">
                            <span class="ad-seg-thumb" aria-hidden="true"></span>
                            <button type="button" class="ad-seg-btn is-on" data-ad-hist="faculty" aria-pressed="true">คณะของฉัน</button>
                            <button type="button" class="ad-seg-btn" data-ad-hist="uni" aria-pressed="false">ทั้งมหาวิทยาลัย</button>
                        </div>
                    </div>
                    <div class="ad-mini" data-hist-panel="faculty">
                        <div><span>การปล่อยสะสมของคณะ</span><b class="ad-cumul" data-count="<?= $pct($o['cumulative']) ?>"><?= $n2($o['cumulative']) ?></b><small>tCO₂e · <?= count($years) ?> ปี</small></div>
                        <div><span>รายการที่กรอกแล้ว ปี <?= $h($year_label) ?></span><?php $ft = $o['fill'][1]['filled'] + $o['fill'][2]['filled'] + $o['fill'][3]['filled']; $fa = $o['fill'][1]['total'] + $o['fill'][2]['total'] + $o['fill'][3]['total']; ?><b class="ad-filled"><?= $ft ?> / <?= $fa ?></b><small>รายการในแม่บท</small></div>
                    </div>
                    <div class="ad-mini" data-hist-panel="uni" hidden>
                        <div><span>การปล่อยสะสมทั้งมหาวิทยาลัย</span><b class="ad-uni-cumul"><?= $n2($o['uni_cumulative']) ?></b><small>tCO₂e · <?= count($years) ?> ปี</small></div>
                        <div><span>หน่วยงานที่มีข้อมูล ปี <?= $h($year_label) ?></span><b class="ad-uni-affils"><?= count($o['ranking']) ?> / <?= $o['affil_total'] ?></b><small>หน่วยงานทั้งหมด</small></div>
                    </div>
                    <div class="ad-chart">
                        <canvas id="adHistory" height="200" aria-label="กราฟการปล่อยรายปีแยกขอบเขต" role="img"></canvas>
                        <span class="ad-legend"><?php foreach ([1, 2, 3] as $s): ?><i style="--c:<?= $scope_meta[$s]['color'] ?>;">ขอบเขต <?= $s ?></i><?php endforeach; ?></span>
                    </div>
                </section>
            </div>
