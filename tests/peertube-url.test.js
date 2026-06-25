/**
 *	tests/peertube-url.test.js
 *	Check the PeerTube URL parsing used by modules/webvideo/webvideo-edit.js.
 *	Framework-free: run with `node tests/peertube-url.test.js` (exit 0 = pass).
 *
 *	This source code is licensed under the GNU General Public License.
 *	See the file COPYING for more details.
 */

const assert = require('assert');

// Must mirror the regex in modules/webvideo/webvideo-edit.js (and .min.js).
const re = /^(?:https?:)?\/\/([^\/?#"'<>]+)\/(?:videos\/(?:watch|embed)|w)\/([^\/?#"'<>]+)/;
const isPeerTube = u =>
	u.indexOf('/videos/watch/') != -1 ||
	u.indexOf('/videos/embed/') != -1 ||
	u.indexOf('/w/') != -1;

// host + id are extracted correctly across the URL shapes PeerTube emits
for (const [url, host, id] of [
	['https://peertube.example.com/w/abc123', 'peertube.example.com', 'abc123'],
	['https://framatube.org/videos/watch/9c9de5e8-0a1e-484a-b099-e80766180a6d', 'framatube.org', '9c9de5e8-0a1e-484a-b099-e80766180a6d'],
	['https://video.ploud.fr/videos/embed/xyz?start=10', 'video.ploud.fr', 'xyz'],
	['//tube.tld:9000/w/short', 'tube.tld:9000', 'short'],
]) {
	assert.ok(isPeerTube(url), 'should detect as peertube: ' + url);
	const m = url.match(re);
	assert.ok(m, 'should parse: ' + url);
	assert.strictEqual(m[1], host, 'host for ' + url);
	assert.strictEqual(m[2], id, 'id for ' + url);
}

// youtube / vimeo links must not be misrouted to the peertube branch
for (const url of ['https://www.youtube.com/watch?v=abc', 'https://vimeo.com/12345']) {
	assert.ok(!isPeerTube(url), 'should not be peertube: ' + url);
}

console.log('peertube-url: all assertions passed');
