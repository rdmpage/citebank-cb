// Shared citation formatting for the CiteBank browse pages.
//
// Two things matter here, and they pull against each other.
//
// Speed: citation-js rebuilds its formatting engine on every
// new Cite(...).format(...) call, at roughly 11ms per record. Formatting a whole
// list in one call costs roughly 0.7ms per record. On the biggest pages that is
// the difference between a tab that locks up and one that renders promptly:
// Zootaxa 2010 holds ~4,200 works, which took ~46s one at a time and ~4s batched.
//
// Fidelity: APA disambiguates entries against their neighbours in the same
// bibliography. Two records by the same author in the same year come out as
// "(1851a)" and "(1851b)", and given names get expanded to tell authors apart.
// That is correct APA, but it is wrong for CiteBank: the point of this database
// is to show records as they actually arrived, so that near-duplicates can be
// compared by eye and clustered. A suffix that exists only because of what sits
// next to a record is an artifact of how we batch, not something in the data --
// and it is worst exactly where it matters most, on the near-identical records
// that clustering is meant to resolve.
//
// So batching is done against a copy of APA with the three disambiguation
// switches turned off. A record then renders identically whether it is formatted
// on its own or in a batch of thousands. If that template cannot be installed we
// fall back to formatting one record at a time, which is slow but still faithful
// -- never to batching with disambiguation left on.

(function (global) {

	function escapeHtml(s) {
		if (s === null || s === undefined) return '';
		return String(s)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	// Resolved once, lazily: 'apa-verbatim' if the non-disambiguating style could
	// be registered, otherwise null (meaning: do not batch).
	var template;

	function citationTemplate() {
		if (template !== undefined) {
			return template;
		}

		template = null;

		try {
			var cfg = Cite.plugins.config.get('@csl');
			var apa = cfg.templates.get('apa');

			// Only the <citation> element carries these; APA's <bibliography>
			// has no neighbour-dependent behaviour (no subsequent-author-substitute).
			var patched = apa.replace(/<citation([^>]*)>/, function (tag, attrs) {
				return '<citation' + attrs.replace(
					/disambiguate-add-(year-suffix|names|givenname)="true"/g,
					'disambiguate-add-$1="false"') + '>';
			});

			if (patched.indexOf('disambiguate-add-year-suffix="false"') === -1) {
				throw new Error('APA style did not contain the expected disambiguation attributes');
			}

			cfg.templates.add('apa-verbatim', patched);
			template = 'apa-verbatim';
		} catch (err) {
			console.log('CiteBank: could not install the non-disambiguating citation style, '
				+ 'falling back to slower per-record formatting: ' + err);
		}

		return template;
	}

	function formatOne(csl, style) {
		return new Cite(csl).format('bibliography',
			{ format: 'html', template: style || 'apa', lang: 'en' });
	}

	// items: [{ id, csl }, ...]
	// Returns an array of HTML strings in the same order as items.
	function formatCitations(items) {
		var style = citationTemplate();

		// No safe batching style: format individually rather than risk suffixes.
		if (!style) {
			return items.map(function (item) {
				try {
					return formatOne(item.csl);
				} catch (err) {
					return escapeHtml((item.csl && item.csl.title) || item.id);
				}
			});
		}

		var byId = {};

		try {
			var payload = items.map(function (item) {
				return Object.assign({}, item.csl, { id: item.id });
			});

			// APA sorts bibliographies alphabetically, so nosort is needed to keep
			// the caller's ordering (page order, in the container view). Rather
			// than trust position, each rendered entry is matched back to its
			// record via the data-csl-entry-id attribute citation-js emits.
			var holder = document.createElement('div');
			holder.innerHTML = new Cite(payload).format('bibliography',
				{ format: 'html', template: style, lang: 'en', nosort: true });

			holder.querySelectorAll('.csl-entry').forEach(function (el) {
				byId[el.getAttribute('data-csl-entry-id')] = el.outerHTML;
			});
		} catch (err) {
			console.log('batch citation formatting failed, falling back per entry: ' + err);
		}

		return items.map(function (item) {
			if (byId[item.id] !== undefined) {
				return byId[item.id];
			}

			try {
				return formatOne(item.csl, style);
			} catch (err) {
				return escapeHtml((item.csl && item.csl.title) || item.id);
			}
		});
	}

	// Convenience wrapper for the common { id -> { csl, cluster_size } } shape
	// the API returns for a year or a page. Returns [{ id, entry, html }, ...]
	// in the object's own key order.
	function formatEntryMap(map) {
		var items = Object.keys(map).map(function (id) {
			return { id: id, csl: map[id].csl };
		});

		var html = formatCitations(items);

		return items.map(function (item, i) {
			return { id: item.id, entry: map[item.id], html: html[i] };
		});
	}

	// Render a list of blocks into an element a chunk at a time.
	//
	// citeproc costs ~5ms per citation however the work is batched -- almost all
	// of it inside its own formatter -- so a big page is simply a lot of work:
	// Zootaxa 2010 is ~4,200 works, about 20 seconds. Doing it in one pass blocks
	// the main thread for that whole time and the tab appears hung. Rendering in
	// chunks puts the first screenful up almost immediately and lets the rest
	// fill in while the page stays scrollable.
	//
	// This is only safe because the citation style has no cross-entry behaviour
	// (see citationTemplate): a record renders identically no matter which chunk
	// it lands in, so chunking changes when output appears, never what it says.
	//
	//   el          - element to append into (caller sets any header content first)
	//   blocks      - array of opaque block objects, in display order
	//   renderBlock - function(block) -> HTML string
	//   sizeOf      - function(block) -> number of citations in that block, so
	//                 chunks are sized by real work rather than block count
	//   budget      - citations per chunk (default 250, roughly a second)
	function renderProgressively(el, blocks, renderBlock, sizeOf, budget) {
		var i = 0;
		var perChunk = budget || 250;

		function step() {
			var html = '';
			var count = 0;

			while (i < blocks.length && count < perChunk) {
				var block = blocks[i++];
				html += renderBlock(block);
				count += sizeOf(block);
			}

			el.insertAdjacentHTML('beforeend', html);

			if (i < blocks.length) {
				setTimeout(step, 0);
			}
		}

		if (blocks.length === 0) {
			return;
		}

		step();
	}

	global.formatCitations     = formatCitations;
	global.formatEntryMap      = formatEntryMap;
	global.renderProgressively = renderProgressively;

})(window);
