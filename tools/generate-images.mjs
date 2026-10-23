/**
 * Generates the fictional sample product images used by the demo seeder.
 *
 * Every image is an original SVG illustration rendered to JPEG with headless
 * Chromium, so the repository never hotlinks or redistributes third-party photos.
 *
 * Usage: npm run images
 */
import { chromium } from 'playwright';
import { mkdir } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const OUT = join( dirname( fileURLToPath( import.meta.url ) ), '..', 'demo', 'images' );

const beans = [
	{ file: 'house-blend', name: 'House Blend', sub: 'Signature Espresso', bg: '#efe3d3', bag: '#3b2a20', label: '#f6efe6', accent: '#c8873a', roast: 3 },
	{ file: 'ethiopia-yirgacheffe', name: 'Yirgacheffe', sub: 'Ethiopia · Washed', bg: '#f3e6de', bag: '#b9573a', label: '#fbf4ee', accent: '#2f5d50', roast: 1 },
	{ file: 'colombia-huila', name: 'Huila Pink Bourbon', sub: 'Colombia · Natural', bg: '#f6e4e6', bag: '#c06a7f', label: '#fff6f6', accent: '#3b2a20', roast: 2 },
	{ file: 'kenya-nyeri', name: 'Nyeri AA', sub: 'Kenya · Washed', bg: '#e4ece3', bag: '#2f5d50', label: '#f3f6f1', accent: '#c8873a', roast: 2 },
	{ file: 'guatemala-antigua', name: 'Antigua', sub: 'Guatemala · Washed', bg: '#ece6da', bag: '#7a5a3a', label: '#faf6ef', accent: '#2f5d50', roast: 3 },
	{ file: 'decaf-colombia', name: 'Swiss Water Decaf', sub: 'Colombia · Decaf', bg: '#e3e8ef', bag: '#3e5a7a', label: '#f4f7fa', accent: '#c8873a', roast: 2 },
	{ file: 'midnight-espresso', name: 'Midnight Espresso', sub: 'Dark Roast Blend', bg: '#e2dcd7', bag: '#1f1a17', label: '#efe8e1', accent: '#d9a54c', roast: 5 },
];

const bagSvg = ( b ) => {
	const dots = Array.from( { length: 5 }, ( _, i ) =>
		`<circle cx="${ 330 + i * 35 }" cy="560" r="11" fill="${ i < b.roast ? b.accent : 'none' }" stroke="${ b.accent }" stroke-width="3"/>`
	).join( '' );
	return `
	<svg xmlns="http://www.w3.org/2000/svg" width="800" height="800" viewBox="0 0 800 800">
		<defs>
			<linearGradient id="shade" x1="0" x2="1">
				<stop offset="0" stop-color="#000" stop-opacity=".18"/>
				<stop offset=".18" stop-color="#000" stop-opacity="0"/>
				<stop offset=".82" stop-color="#000" stop-opacity="0"/>
				<stop offset="1" stop-color="#000" stop-opacity=".22"/>
			</linearGradient>
			<radialGradient id="glow" cx=".5" cy=".42" r=".6">
				<stop offset="0" stop-color="#fff" stop-opacity=".55"/>
				<stop offset="1" stop-color="#fff" stop-opacity="0"/>
			</radialGradient>
		</defs>
		<rect width="800" height="800" fill="${ b.bg }"/>
		<rect width="800" height="800" fill="url(#glow)"/>
		<ellipse cx="400" cy="700" rx="230" ry="26" fill="#000" opacity=".12"/>
		<path d="M220 170 L580 170 L600 690 Q400 712 200 690 Z" fill="${ b.bag }"/>
		<path d="M220 170 L580 170 L600 690 Q400 712 200 690 Z" fill="url(#shade)"/>
		<rect x="214" y="120" width="372" height="62" rx="6" fill="${ b.bag }"/>
		<rect x="214" y="120" width="372" height="62" rx="6" fill="#000" opacity=".18"/>
		<g stroke="#fff" stroke-opacity=".25" stroke-width="2">
			${ Array.from( { length: 17 }, ( _, i ) => `<line x1="${ 232 + i * 21 }" y1="130" x2="${ 232 + i * 21 }" y2="172"/>` ).join( '' ) }
		</g>
		<rect x="262" y="250" width="276" height="370" rx="18" fill="${ b.label }"/>
		<circle cx="400" cy="318" r="40" fill="none" stroke="${ b.bag }" stroke-width="5"/>
		<path d="M400 290 C382 305 382 331 400 346 C418 331 418 305 400 290 Z" fill="${ b.bag }"/>
		<path d="M400 293 C394 310 406 326 400 343" stroke="${ b.label }" stroke-width="3" fill="none"/>
		<text x="400" y="392" text-anchor="middle" font-family="Georgia, serif" font-size="17" letter-spacing="5" fill="${ b.bag }">BREW &amp; BEAN</text>
		<line x1="300" y1="410" x2="500" y2="410" stroke="${ b.accent }" stroke-width="2"/>
		${ wrap( b.name ).map( ( l, i, a ) => `<text x="400" y="${ 455 + i * 40 - ( a.length - 1 ) * 14 }" text-anchor="middle" font-family="Georgia, serif" font-weight="700" font-size="36" fill="${ b.bag }">${ l }</text>` ).join( '' ) }
		<text x="400" y="525" text-anchor="middle" font-family="Helvetica, Arial, sans-serif" font-size="17" letter-spacing="1.5" fill="${ b.bag }" opacity=".75">${ b.sub.toUpperCase() }</text>
		${ dots }
		<text x="400" y="600" text-anchor="middle" font-family="Helvetica, Arial, sans-serif" font-size="13" letter-spacing="3" fill="${ b.bag }" opacity=".6">ROAST LEVEL</text>
	</svg>`;
};

function wrap( text ) {
	if ( text.length <= 13 ) {
		return [ text ];
	}
	const words = text.split( ' ' );
	const mid = Math.ceil( words.length / 2 );
	return [ words.slice( 0, mid ).join( ' ' ), words.slice( mid ).join( ' ' ) ];
}

const scene = ( bg, inner ) => `
	<svg xmlns="http://www.w3.org/2000/svg" width="800" height="800" viewBox="0 0 800 800">
		<defs><radialGradient id="glow" cx=".5" cy=".42" r=".6"><stop offset="0" stop-color="#fff" stop-opacity=".6"/><stop offset="1" stop-color="#fff" stop-opacity="0"/></radialGradient></defs>
		<rect width="800" height="800" fill="${ bg }"/><rect width="800" height="800" fill="url(#glow)"/>
		${ inner }
	</svg>`;

const gear = [
	{
		file: 'gooseneck-kettle',
		svg: scene( '#e6e9e4', `
			<ellipse cx="400" cy="660" rx="240" ry="24" fill="#000" opacity=".12"/>
			<path d="M250 380 Q250 330 300 330 L500 330 Q550 330 550 380 L560 640 Q400 668 240 640 Z" fill="#2b2b2b"/>
			<path d="M262 380 L270 630" stroke="#fff" stroke-opacity=".18" stroke-width="16" stroke-linecap="round"/>
			<rect x="300" y="300" width="200" height="36" rx="10" fill="#1d1d1d"/>
			<circle cx="400" cy="288" r="18" fill="#c8873a"/>
			<path d="M550 400 Q640 420 640 520 L640 600" stroke="#2b2b2b" stroke-width="34" fill="none" stroke-linecap="round"/>
			<path d="M250 600 Q170 590 150 470 Q140 400 110 360" stroke="#2b2b2b" stroke-width="18" fill="none" stroke-linecap="round"/>
			<rect x="230" y="640" width="340" height="22" rx="8" fill="#c8873a"/>` ),
	},
	{
		file: 'ceramic-dripper',
		svg: scene( '#f1e7dc', `
			<ellipse cx="400" cy="660" rx="200" ry="22" fill="#000" opacity=".12"/>
			<rect x="300" y="520" width="200" height="140" rx="30" fill="#c8873a"/>
			<path d="M300 560 Q240 560 240 600 Q240 640 300 640" stroke="#c8873a" stroke-width="22" fill="none"/>
			<rect x="250" y="490" width="300" height="34" rx="12" fill="#24493f"/>
			<path d="M210 240 L590 240 L470 490 L330 490 Z" fill="#2f5d50"/>
			${ [ 0, 1, 2, 3, 4 ].map( ( i ) => `<line x1="${ 270 + i * 65 }" y1="260" x2="${ 360 + i * 20 }" y2="470" stroke="#3f7566" stroke-width="6"/>` ).join( '' ) }
			<rect x="200" y="226" width="400" height="22" rx="10" fill="#24493f"/>` ),
	},
	{
		file: 'hand-grinder',
		svg: scene( '#e9e2d8', `
			<ellipse cx="400" cy="680" rx="150" ry="20" fill="#000" opacity=".12"/>
			<rect x="320" y="300" width="160" height="380" rx="26" fill="#9aa1a6"/>
			<rect x="332" y="310" width="24" height="360" rx="12" fill="#fff" opacity=".35"/>
			<rect x="320" y="470" width="160" height="70" fill="#5b3a29"/>
			<rect x="320" y="470" width="160" height="70" fill="url(#glow)" opacity=".2"/>
			<rect x="310" y="280" width="180" height="34" rx="12" fill="#6d757b"/>
			<rect x="390" y="190" width="20" height="100" fill="#3a3a3a"/>
			<rect x="390" y="190" width="210" height="20" rx="10" fill="#3a3a3a"/>
			<circle cx="600" cy="200" r="26" fill="#5b3a29"/>` ),
	},
	{
		file: 'brew-scale',
		svg: scene( '#e3e6ea', `
			<ellipse cx="400" cy="580" rx="280" ry="26" fill="#000" opacity=".14"/>
			<rect x="140" y="420" width="520" height="150" rx="28" fill="#1f2326"/>
			<rect x="160" y="400" width="480" height="40" rx="16" fill="#2c3236"/>
			<rect x="300" y="470" width="200" height="60" rx="8" fill="#0d1a12"/>
			<text x="400" y="514" text-anchor="middle" font-family="Courier New, monospace" font-weight="700" font-size="36" fill="#6be08f">18.0g</text>
			<circle cx="210" cy="500" r="16" fill="#3d4448"/><circle cx="590" cy="500" r="16" fill="#3d4448"/>
			<rect x="300" y="290" width="200" height="110" rx="22" fill="#f8f4ee"/>
			<path d="M500 320 Q550 320 550 345 Q550 370 500 370" stroke="#f8f4ee" stroke-width="16" fill="none"/>
			<ellipse cx="400" cy="296" rx="96" ry="12" fill="#5b3a29"/>` ),
	},
	{
		file: 'paper-filters',
		svg: scene( '#f2ece2', `
			<ellipse cx="400" cy="640" rx="230" ry="22" fill="#000" opacity=".1"/>
			${ [ 0, 1, 2, 3, 4, 5 ].map( ( i ) => `<path d="M${ 200 + i * 6 } ${ 300 + i * 40 } L${ 600 - i * 6 } ${ 300 + i * 40 } L${ 470 - i * 4 } ${ 520 + i * 20 } L${ 330 + i * 4 } ${ 520 + i * 20 } Z" fill="${ i % 2 ? '#fbf8f2' : '#f3eadc' }" stroke="#dccfbd" stroke-width="3"/>` ).join( '' ) }
			<rect x="270" y="560" width="260" height="70" rx="12" fill="#2f5d50"/>
			<text x="400" y="604" text-anchor="middle" font-family="Georgia, serif" font-size="26" fill="#f6efe6">100 FILTERS</text>` ),
	},
];

const extras = [
	{
		file: 'gallery-beans',
		svg: scene( '#4a3426', Array.from( { length: 46 }, ( _, i ) => {
			const x = ( i * 137 ) % 760 + 20;
			const y = ( i * 89 ) % 760 + 20;
			const r = ( i * 47 ) % 180;
			return `<g transform="translate(${ x } ${ y }) rotate(${ r })"><ellipse rx="44" ry="30" fill="${ i % 3 ? '#6b4430' : '#7d5038' }"/><path d="M-36 0 Q0 -14 36 0" stroke="#2a1b13" stroke-width="6" fill="none"/></g>`;
		} ).join( '' ) ),
	},
	{
		file: 'gallery-cup',
		svg: scene( '#efe3d3', `
			<ellipse cx="400" cy="560" rx="260" ry="60" fill="#fff"/>
			<ellipse cx="400" cy="560" rx="260" ry="60" fill="#000" opacity=".05"/>
			<path d="M250 360 L550 360 L520 540 Q400 580 280 540 Z" fill="#f8f4ee"/>
			<path d="M550 400 Q620 400 620 450 Q620 500 540 505" stroke="#f8f4ee" stroke-width="22" fill="none"/>
			<ellipse cx="400" cy="362" rx="150" ry="26" fill="#6b4430"/>
			<path d="M360 362 Q400 340 440 362 Q400 384 360 362 Z" fill="#c8a27a"/>
			<path d="M340 300 Q360 270 340 240 Q320 210 340 180" stroke="#fff" stroke-width="8" fill="none" opacity=".7" stroke-linecap="round"/>
			<path d="M420 290 Q440 260 420 230 Q400 200 420 170" stroke="#fff" stroke-width="8" fill="none" opacity=".7" stroke-linecap="round"/>` ),
	},
];

await mkdir( OUT, { recursive: true } );
const browser = await chromium.launch();
const page = await browser.newPage( { viewport: { width: 800, height: 800 } } );
const all = [ ...beans.map( ( b ) => ( { file: b.file, svg: bagSvg( b ) } ) ), ...gear, ...extras ];
for ( const item of all ) {
	await page.setContent( `<html><body style="margin:0">${ item.svg }</body></html>` );
	await page.screenshot( { path: join( OUT, `${ item.file }.jpg` ), type: 'jpeg', quality: 82 } );
	console.log( 'wrote', item.file );
}
await browser.close();
