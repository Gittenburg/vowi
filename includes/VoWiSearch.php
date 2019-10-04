<?php

class_alias(SearchEngineFactory::getSearchEngineClass(wfGetDB(DB_REPLICA)), 'DatabaseSearch');

class VoWiSearch extends DatabaseSearch {
	protected function completionSearchBackend( $search ) {
		$backend = new VoWiTitlePrefixSearch;
		$results =  $backend->defaultSearchBackend( $this->namespaces, $search, $this->limit, $this->offset );
		return SearchSuggestionSet::fromTitles( $results );
	}
}

class VoWiTitlePrefixSearch extends TitlePrefixSearch {
	// The code of this function was initially copied from PrefixSearch::defaultSearchBackend().

	public function defaultSearchBackend( $namespaces, $search, $limit, $offset ) {
		global $wgContLang;
		global $wgOutdatedLVACategory;
		// Backwards compatability with old code. Default to NS_MAIN if no namespaces provided.
		if ( $namespaces === null ) {
			$namespaces = [];
		}
		if ( !$namespaces ) {
			$namespaces[] = NS_MAIN;
		}

		// Construct suitable prefix for each namespace. They differ in cases where
		// some namespaces always capitalize and some don't.
		$prefixes = [];
		foreach ( $namespaces as $namespace ) {
			// For now, if special is included, ignore the other namespaces
			if ( $namespace == NS_SPECIAL ) {
				return $this->specialSearch( $search, $limit, $offset );
			}

			$prefix = $wgContLang->caseFold( $search );
			$prefixes[$prefix][] = $namespace;
		}

		$dbr = wfGetDB( DB_REPLICA );
		// Often there is only one prefix that applies to all requested namespaces,
		// but sometimes there are two if some namespaces do not always capitalize.
		$conds = [];
		foreach ( $prefixes as $prefix => $namespaces ) {
			$condition = [
				'page_namespace' => $namespaces,

				// Modification: use Extension:TitleKey for case-insensitive searches
				'tk_key' . $dbr->buildLike( $prefix, $dbr->anyString() ),
			];

			if (strpos($search, '/') == false)
				// Modification: exclude subpages by default because we have so many
				$condition[] = 'NOT page_title' . $dbr->buildLike( $dbr->anyString(), '/', $dbr->anyString());

			$conds[] = $dbr->makeList( $condition, LIST_AND );
		}

		// Modification: demote pages in $wgOutdatedLVACategory
		$table = ['page', 'outdated' => 'categorylinks', 'tk' => 'titlekey'];
		$fields = [ 'page_id', 'page_namespace', 'page_title',
			'if(cl_from is NULL,0,1) as outdated',
			'CASE WHEN page_namespace in (3000,3002,3004,3006) THEN 1
			      ELSE page_namespace END AS ns_key'];
		$conds = $dbr->makeList( $conds, LIST_OR );
		$options = [
			'LIMIT' => $limit,
			'ORDER BY' => [ 'outdated', 'ns_key', 'page_title', 'page_namespace'],
			'OFFSET' => $offset
		];
		$join_conds = [
			'outdated' => ['LEFT JOIN', ['page_id=cl_from', 'cl_to'=>$wgOutdatedLVACategory]],
			'tk' => ['JOIN', ['tk_page=page_id']]
		];

		$res = $dbr->select( $table, $fields, $conds, __METHOD__, $options, $join_conds );

		return iterator_to_array( TitleArray::newFromResult( $res ) );
	}
}
