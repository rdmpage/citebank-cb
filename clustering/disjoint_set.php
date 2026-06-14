<?php

//----------------------------------------------------------------------------------------
// Disjoint-set data structure

// https://en.wikipedia.org/wiki/Disjoint-set_data_structure

class DisjointSet
{
	var $parent = array();
	var $rank = array();
	
	//------------------------------------------------------------------------------------
	// Initialise two empty arrays for parents and ranks
	function __construct()
	{
		$this->parent = array();
		$this->rank = array();
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
		$this->rank[$x]   = 0;	
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
	// Merge two nodes
	function union($x, $y)
	{
		$x = $this->find($x);
		$y = $this->find($y);
			
		// same parent so already part of same cluster
		if ($x == $y)
		{
			return;
		}
	
		// ensure x is not lower rank than y
		if ($this->rank[$x] < $this->rank[$y])
		{
			[$x, $y] = [$y, $x];
		}
	
		// parent is x
		$this->parent[$y] = $x;
	
		// update rank of x
		$this->rank[$x] += $this->rank[$y];
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
