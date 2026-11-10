// Case for Raspberry Pi 3 Model B + 2.8" "RPi Display" HAT (ILI9341, K1-K3 buttons) - pi-monitor project.
// Two printed parts: a base tray holding the Pi, and a lid with a window for the screen and holes for K1-K3.
//
// Open in OpenSCAD (free, openscad.org). Set `part` below, press F6 (render), then File > Export > STL.
// Print both parts flat side down: base as modelled, lid upside down (it's modelled that way already).
//
// Coordinates: millimetres, origin at the Pi board's corner at the SD-card end and the power/HDMI edge,
//   x along the board (85 mm, towards the USB/Ethernet end), y across it (56 mm, towards the GPIO header),
//   z up from the underside of the Pi board.
// Pi 3B values are from the official mechanical drawing. Values marked EST are estimates from photos:
// measure them and edit before printing (see README, "Case").

part = "both";          // "base", "lid", "both" (side by side) or "assembled" (lid in place, to check the fit)

/* ---------------- Raspberry Pi 3 Model B (official dimensions) ---------------- */
pi_l = 85;              // board length
pi_w = 56;              // board width
pi_t = 1.4;             // board thickness
pi_r = 3;               // corner radius
holes = [[3.5, 3.5], [61.5, 3.5], [3.5, 52.5], [61.5, 52.5]];   // M2.5 mounting holes
usb_overhang = 2.2;     // USB/Ethernet jacks stick out past the board end by about this

// Ports: [centre along the edge, width, bottom z above the board top, height]
micro_usb = [10.6, 12, -1, 8];     // power; widened for the plug's moulding
hdmi      = [32.0, 21, -1, 11];    // widened for the plug's moulding
audio_x   = 53.5;                  // 3.5 mm jack, centre 3 mm above the board top
ethernet  = [10.25, 17, -0.5, 14.5];
usb_a     = [[29.0, 15, -0.5, 16.5], [47.0, 15, -0.5, 16.5]];
sd_y      = 28;                    // micro SD slot under the board at the x = 0 end

/* ---------------- HAT (EST: measure these) ---------------- */
hat_gap   = 16;         // EST: Pi board top -> underside of the HAT board (it seems to rest on the USB jacks)
hat_t     = 1.6;        // HAT board thickness
lcd_h     = 3.6;        // EST: HAT board top -> top of the screen glass
// Visible screen area (2.8" ILI9341 = 57.6 x 43.2 mm), position over the Pi board:
view_x0   = 10.5;       // EST: Pi board's SD-card end -> left edge of the lit area
view_y0   = 9.3;        // EST: Pi board's power/HDMI edge -> near edge of the lit area
view_l    = 57.6;
view_w    = 43.2;
view_margin = 0.8;      // window slightly bigger than the lit area
// Buttons K1-K3 (tactile switches on the HAT's top face), centres over the Pi board:
btn_x     = 76;         // EST
btn_y     = [16.5, 30, 42.5];   // EST: K1, K2, K3
btn_d     = 6.5;        // hole diameter (fingertip / pen access)
btn_h     = 3.5;        // EST: switch height above the HAT board

/* ---------------- Case ---------------- */
wall      = 2;
floor_t   = 2;
top_t     = 1.6;        // lid plate thickness over the screen
gap       = 0.6;        // clearance around the board
standoff_h = 4;         // room under the Pi (SD card, solder joints)
standoff_d = 6;
screw_d   = 2.2;        // M2.5 self-tapping into the standoff
lip_h     = 3;          // the lid's skirt overlaps the outside of the base rim by this much
rebate    = 1;          // the base rim is stepped in by this much on the outside to take the lid's skirt
fit       = 0.25;       // clearance between the lid skirt and the base rim
vent_w    = 2;          // ventilation slot width
$fn = 40;

/* ---------------- derived ---------------- */
board_z   = floor_t + standoff_h;                 // underside of the Pi board
pi_top    = board_z + pi_t;
in_x0 = -gap;  in_x1 = pi_l + gap;                // inside of the walls (jacks poke through the x1 wall)
in_y0 = -gap;  in_y1 = pi_w + gap;
out_x0 = in_x0 - wall;  out_x1 = in_x1 + wall;
out_y0 = in_y0 - wall;  out_y1 = in_y1 + wall;
split_z   = pi_top + 17;                          // base walls end just above the USB jacks
glass_z   = pi_top + hat_gap + hat_t + lcd_h;     // top of the screen glass
lid_top   = glass_z + 0.3 + top_t;                // underside of the lid plate sits just above the glass
echo(str("Case outside: ", out_x1 - out_x0, " x ", out_y1 - out_y0, " x ", lid_top, " mm"));
assert(lid_top > split_z + 2, "The screen sits lower than the USB jacks: check hat_gap");

module rbox(x0, y0, x1, y1, z0, z1, r = 3) {
    translate([0, 0, z0]) linear_extrude(z1 - z0)
        offset(r) offset(-r) translate([x0, y0]) square([x1 - x0, y1 - y0]);
}

module base() {
    difference() {
        rbox(out_x0, out_y0, out_x1, out_y1, 0, split_z, pi_r + wall);
        rbox(in_x0, in_y0, in_x1, in_y1, floor_t, split_z + 1, pi_r);
        // power, HDMI and audio on the y = 0 edge
        for (p = [micro_usb, hdmi])
            translate([p[0] - p[1] / 2, out_y0 - 1, pi_top + p[2]]) cube([p[1], wall + 2, p[3]]);
        translate([audio_x, out_y0 - 1, pi_top + 3]) rotate([-90, 0, 0]) cylinder(d = 8, h = wall + 2);
        // Ethernet and USB on the x = 85 end
        for (p = concat([ethernet], usb_a))
            translate([in_x1 - 1, p[0] - p[1] / 2, pi_top + p[2]]) cube([wall + 2, p[1], p[3]]);
        // micro SD slot under the board at the x = 0 end
        translate([out_x0 - 1, sd_y - 8, floor_t + 0.5]) cube([wall + 2, 16, standoff_h + pi_t]);
        // floor vents
        for (i = [0:6]) translate([18 + i * 7, 12, -1]) cube([vent_w, 32, floor_t + 2]);
        // rebate round the outside of the rim, for the lid's skirt (keeps the inside clear for the HAT)
        difference() {
            rbox(out_x0 - 1, out_y0 - 1, out_x1 + 1, out_y1 + 1, split_z - lip_h, split_z + 1, pi_r + wall);
            rbox(out_x0 + rebate, out_y0 + rebate, out_x1 - rebate, out_y1 - rebate,
                 split_z - lip_h - 1, split_z + 2, pi_r + wall - rebate);
        }
    }
    // standoffs
    for (h = holes) translate([h[0], h[1], 0]) difference() {
        cylinder(d = standoff_d, h = board_z);
        translate([0, 0, floor_t]) cylinder(d = screw_d, h = board_z);
    }
}

module lid_shape() {   // modelled in place over the base
    difference() {
        rbox(out_x0, out_y0, out_x1, out_y1, split_z - lip_h, lid_top, pi_r + wall);
        // skirt: fits over the base's stepped-in rim
        rbox(out_x0 + rebate - fit, out_y0 + rebate - fit, out_x1 - rebate + fit, out_y1 - rebate + fit,
             split_z - lip_h - 1, split_z, pi_r + wall - rebate);
        // hollow inside, below the top plate
        rbox(in_x0, in_y0, in_x1, in_y1, split_z - 1, lid_top - top_t, pi_r);
        // screen window, with a 45-degree bevel so the edges don't shade the picture
        translate([view_x0 - view_margin, view_y0 - view_margin, lid_top - top_t - 0.01])
            hull() {
                cube([view_l + 2 * view_margin, view_w + 2 * view_margin, 0.01]);
                translate([-top_t, -top_t, top_t]) cube([view_l + 2 * view_margin + 2 * top_t,
                                                         view_w + 2 * view_margin + 2 * top_t, 0.03]);
            }
        // K1-K3 button holes
        for (y = btn_y) translate([btn_x, y, lid_top - top_t - 1]) cylinder(d = btn_d, h = top_t + 2);
        // the skirt would cover the tops of the Ethernet/USB openings: cut it away there
        for (p = concat([ethernet], usb_a))
            translate([in_x1 - 1, p[0] - p[1] / 2, split_z - lip_h - 1]) cube([wall + 3, p[1], lip_h + 1]);
        // side vents near the top
        for (i = [0:4]) translate([20 + i * 9, out_y1 - wall - 1, split_z + 2]) cube([vent_w, wall + 2, 4]);
    }
}

module lid() {   // flipped onto its top for printing (a rotation, not a mirror, so the holes stay in place)
    translate([0, 0, lid_top]) rotate([180, 0, 0]) lid_shape();
}

if (part == "base") base();
else if (part == "lid") lid();
else if (part == "assembled") { base(); lid_shape(); }   // check the fit
else {
    base();
    translate([0, 2 * (out_y1 - out_y0) + 10, 0]) lid();
}
