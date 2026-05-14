<?php

//----------------------------------------------------------------------------------------
// Disjoint-set data structure

// https://en.wikipedia.org/wiki/Disjoint-set_data_structure

class DisjointSet
{
	var $parent = array();

	//------------------------------------------------------------------------------------
	function __construct()
	{
		$this->parent = array();
	}

	//------------------------------------------------------------------------------------
	function exists($x)
	{
		return isset($this->parent[$x]);
	}

	//------------------------------------------------------------------------------------
	// Initialise element to be a member of its own set
	function makeset($x)
	{
		$this->parent[$x] = $x;
	}

	//------------------------------------------------------------------------------------
	// Find a node with path compression
	function find($x)
	{
		if ($this->parent[$x] != $x)
		{
			$this->parent[$x] = $this->find($this->parent[$x]);

		}
		return $this->parent[$x];
	}

	//------------------------------------------------------------------------------------
	// Merge two nodes. The lexicographically smaller root becomes the parent, so the
	// cluster ID is a deterministic function of cluster membership (not merge order).
	function union($x, $y)
	{
		$x = $this->find($x);
		$y = $this->find($y);

		// same parent so already part of same cluster
		if ($x === $y)
		{
			return;
		}

		if (strcmp((string)$x, (string)$y) < 0)
		{
			$this->parent[$y] = $x;
		}
		else
		{
			$this->parent[$x] = $y;
		}
	}
	
	//------------------------------------------------------------------------------------
	// Dump disjoint set structure
	function dump()
	{
		echo "Disjoint set forest\n";
		foreach ($this->parent as $x => $parent)
		{
			echo $x .  ' -> ' . $parent . "\n";
		}	
	}
	
	//------------------------------------------------------------------------------------
	// Return list of clusters, note that we use find to compress paths so that
	// every member of a cluster has the same parent
	function clusters()
	{
		$clusters = [];
		
		foreach ($this->parent as $x => $parent)
		{
			$r = $this->find($x); // compress
			if (!isset($clusters[$r]))
			{
				$clusters[$r] = [];				
			}
			$clusters[$r][] = $x;
		}
		
		return $clusters;	
	}
		
	//------------------------------------------------------------------------------------
	// Output disjoint sets in Graphviz DOT format
	function dot($labels = [])
	{
		$g = "digraph g {\nrankdir=LR;\n";
		
		if (count($labels) > 0)
		{
			foreach ($labels as $id => $label)
			{
				$g .= "node [label=\"" . str_replace('"', '\"', $label) . "\"] " . $id . ";\n";
			}
		}
		
		foreach ($this->parent as $x => $parent)
		{
			if ($x != $parent)
			{
				$g .= "$x -> $parent;\n";
			}
		}	
		
		$g .= "}\n";
		
		return $g;
	}	
	
	//------------------------------------------------------------------------------------
	// Output disjoint sets in Mermaid format
	function mermaid($labels = [])
	{
		$g = "graph LR\n";
		
		if (count($labels) > 0)
		{
			foreach ($labels as $id => $label)
			{
				$g .= $id . "[\"" . $id . ":" . $label . "\"]\n";
			}
		}
		
		foreach ($this->parent as $x => $parent)
		{
			if ($x != $parent)
			{
				$g .= "$x --> $parent;\n";
			}
		}	

		
		return $g;
	}	
	
}

?>
