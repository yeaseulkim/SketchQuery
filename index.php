<!DOCTYPE html>

<meta charset="utf-8" />
<head>
<link rel="stylesheet" type="text/css" href="style.css" />
<script src="colorbrewer/colorbrewer.js"></script>
<script src="http://code.jquery.com/jquery-latest.min.js" type="text/javascript"></script>
<link type="text/css" rel="stylesheet" href="colorbrewer/colorbrewer.css"/>
<script src="//d3js.org/d3.v3.min.js" charset="utf-8"></script>
</head>

<body onresize="resize()">
<div id="multiples"></div>

<div id="results"></div>
<div id="search"></div>
<div id="searchOption">

<table class="searchTable">
<tr><td>Find the top <input type="number" defaultvalue="5" id="n" max="10" min="1"  name="n" step="1" value="5" /> series. Show results <select id="resultMode" name="resultMode"><option value="0">in seperate graphs</option><option value="1">in the same graph</option></select>.</td><td>X Position</td><td>Y Position</td><td>Noise</td><td>Amplitude</td><td>Warp</td></tr>
<tr><td>Use <select id="queryMode" onchange="setInvariants()" name="queryMode"><option value="0">MSE (value matching)</option><option value="1">Hough (pattern matching)</option><option value="2">DTW (time warped value matching)</option></select>, ignoring: </td><td><input type="checkbox" name="xpos" value="XPos" id="XPos" /></td><td><input type="checkbox" name="ypos" value="YPos" id="YPos" disabled="true" /></td><td><input type="checkbox" name="noise" value="Noise" id="Noise" /></td><td><input type="checkbox" name="amp" value="Amp" id="Amp" disabled="true"/></td><td><input type="checkbox" name="warp" value="Warp" id="Warp" disabled="true" /></td></tr>
</table>

</div>

<div id="searchUI"></div>

</body>

<script>


//Global Variables

var draggable;
var lastTouched = -1;

var margin = {top: 8, right: 10, bottom: 2, left: 10},
    width = document.getElementById("multiples").scrollWidth,
    height = 30 - margin.top - margin.bottom;


var parseDate = d3.time.format("%x").parse;

var x = d3.time.scale()
    .range([0, width]);

var rectWidth;
var searchCanvas;
var bounds;

var query;
var sparseQuery;
var queryRange;
var queryMin;
var queryMax;

var drawing = false;
var dMode = 0;
var context;
var oldX,oldY = 0;
var queryY = d3.scale.linear().domain([0,150]);
var hitColorScale = d3.scale.quantize().domain([0,-100]).range(colorbrewer.RdYlGn[9]);
var windowColorScale = d3.scale.quantize().range(colorbrewer.RdYlGn[9]);

var rwidth;
var rX;
var rY;

var nanHeight = 3;

var searchMode = 0;
var votes;
var voteBuffer = 1.0;
var voteScale = 0.25;

var resultMode = 0;
var sendData;

//Utility functions


function getTime(obj){
	return <?php if(isset($_GET['time'])&&$_GET['time']!=null&&$_GET['time']!='date'){
		echo 'obj.'.$_GET['time'];		
	}
	else{
		echo 'obj.date';
	}	?>;
}

function getFormat(){
        return <?php if(isset($_GET['time'])&&$_GET['time']=='date'){
        	        echo 'd3.time.format("%m/%Y")';
	        }
	        else{
	                echo 'd3.format("d")';
	        }       ?>;
}

function getTimeString(){
	return "<?php if(isset($_GET['time'])){
			echo $_GET['time'];
		}
		else{
			echo 'data';
		} ?>";
}

function getValue(obj){
	return <?php if(isset($_GET['value'])&&$_GET['value']!=null){
 			echo 'obj.'.$_GET['value'];
		}
		else{
			 echo 'obj.freq';
		}?>
}

function getValueString(){
        return "<?php if(isset($_GET['value'])){
	              	echo $_GET['value'];
		}
		else{
	                echo 'freq';									                } ?>";
}

function resize(){
    margin = {top: 8, right: 10, bottom: 2, left: 10};
    width = document.getElementById("multiples").scrollWidth;
    height = 30 - margin.top - margin.bottom;
    rwidth = d3.select("#results").node().scrollWidth - 20;
    bounds = searchCanvas.getBoundingClientRect();

    
    rY = d3.scale.linear().domain([0,height]).range([searchCanvas.height,0]);
    makeVotes();
    
}

function type(d) {
  d.<?php if(isset($_GET['value'])&&$_GET['value']!=null){ echo $_GET['value'];}else{ echo 'freq';}?> = +d.<?php if(isset($_GET['value'])&&$_GET['value']!=null){ echo $_GET['value'];}else{ echo 'freq';}?>;
<?php if($_GET['time']=='date'){
	echo 'd.date = parseDate(d.date);';
	}
	else{
	echo 'd.date = +d.date;';
	}
	?>
  return d;
}


//Data loading and initialization
d3.csv("<?php if(isset($_GET['data'])&&$_GET['data']!=null){
echo 'datasets/'.$_GET['data'].'.csv';
}
else{
echo "datasets/onemilliontop1k1800.csv";}
?>", type, function(error, data) {
 document.body.style.cursor="wait"
 var symbols = d3.nest()
      .key(function(d) { return d.<?php if(isset($_GET['name'])&&$_GET['name']!=null){ echo $_GET['name'];}else{ echo 'word';}?>; })
      .entries(data);

  x.domain([
    d3.min(symbols, function(symbol) { return getTime(symbol.values[0]);}),
    d3.max(symbols, function(symbol) { return getTime(symbol.values[symbol.values.length - 1]); })
  ]);

 	
  var svg = d3.select("#multiples").selectAll("svg")
      .data(symbols)
    .enter().append("svg")
      .attr("width", width)
      .attr("height", height + margin.top + margin.bottom)
      .attr("id",function(symbol){ return "key"+symbol.key;})
      .attr("ondblclick","transferQuery(event)")
      .attr("ontouchstart","touchInit(event)")
      .attr("ontouchend","touchTransfer(event)")
    .append("g")
      .attr("transform", "translate(" + 0  + "," + margin.top + ")")
      .each(function(symbol,i) {
        symbol.y = d3.scale.linear()
            .domain([d3.min(symbol.values, function(d){return getValue(d);}),d3.max(symbol.values,function(d){return getValue(d);})])
            .range([height, 0]);
	symbol.rankOrder = i;
	symbol.bestX = 0;
	symbol.bestHit = 1;
      });

  svg.append("path")
      .attr("class", "area")
      .attr("d", function(symbol) {
        return d3.svg.area()
            .x(function(d) { return x(getTime(d)); })
            .y1(function(d) { return symbol.y(getValue(d)); })
            .y0(height)
            (symbol.values);
      });

  svg.append("path")
      .attr("class", "line")
      .attr("d", function(symbol) {
        return d3.svg.line()
           .x(function(d) { return x(getTime(d)); })
           .y(function(d) { return symbol.y(getValue(d)); })
            (symbol.values);
      });

 svg.append("text")
      .attr("x", width - margin.right - 6)
      .attr("y", 6)
      .attr("fill","#000")
      .style("text-anchor", "end")
      .style("stroke","#fff")
      .style("stroke-width","2")
      .text(function(symbol) { return symbol.key; });	

 svg.append("text")
      .attr("x", width - margin.right - 6)
      .attr("y", 6)
      .attr("fill","#000")
      .style("text-anchor", "end")
      
      .text(function(symbol) { return symbol.key; });



//Make Visual Query Area
	rwidth = d3.select("#results").node().scrollWidth - 20;
	rX = d3.time.scale();
	
	rX.domain([
    d3.min(symbols, function(symbol) { return getTime(symbol.values[0]);}),
    d3.max(symbols, function(symbol) { return getTime(symbol.values[symbol.values.length - 1]); })
  ]);
	rX.range([0,rwidth]);
	var xAxis = d3.svg.axis().orient("top").scale(rX).tickSize(10).tickFormat(getFormat()).ticks(20);

	d3.select("#search")
		.append("svg")
		.attr("width", rwidth)
		.attr("height","25")
		.append("g")
			.attr("transform","translate(0,25)")
			.attr("class","xaxis")
			.call(xAxis);

 d3.select("#search")
	.append("canvas")
	.attr("id","queryCanvas")   
	.attr("width",rwidth)
	.attr("height",document.getElementById("search").clientHeight-25);


 d3.select("#searchUI")
	.append("button")
	.attr("onclick","search()")
	.attr("class","searchbtn")
	.text("")
	.append("img")
	.attr("src","imgs/search.png");
	

 d3.select("#searchUI")
	.append("button")
	.attr("onclick","resetQuery()")
	.attr("id","clearBtn")
	.text("")
	.append("img")
	.attr("src","imgs/clear.png");


 d3.select("#searchUI")
	.append("button")
	.attr("onclick","drawMode()")
	.attr("id","drawBtn")
	.text("")
	.append("img")
	.attr("src","imgs/draw-active.png");

 d3.select("#searchUI")
	.append("button")
	.attr("onclick","eraseMode()")
	.attr("id","eraseBtn")
	.text("")
	.append("img")
	.attr("src","imgs/erase.png");

 searchCanvas = document.getElementById("queryCanvas");

 var searchArea = document.getElementById("search");
 searchArea.addEventListener("mousedown",mouseDown,false);
 searchArea.addEventListener("mouseup",mouseUp,false);
 searchArea.addEventListener("mousemove",mouseMove,false);
 searchArea.addEventListener("mouseleave",mouseLeave,false);


 searchArea.addEventListener("touchstart",mouseDown,false);
 searchArea.addEventListener("touchmove",mouseMove,false);
 searchArea.addEventListener("touchend",mouseUp,false);
 searchArea.addEventListener("touchleave",mouseLeave,false);

 bounds = searchCanvas.getBoundingClientRect();
 makeVotes();


// prevent elastic scrolling
 searchArea.addEventListener('touchmove',function(event){
  event.preventDefault();
 },false);	// end body:touchmove

//Initialize query
 query = new Array(searchCanvas.width+1);
 sparseQuery = new Array();
 for(var i = 0;i<query.length;i++){
 	query[i] = -1;
 }

   rY = d3.scale.linear().domain([0,height]).range([searchCanvas.height,0]);


  context = searchCanvas.getContext("2d");
  document.body.style.cursor="default";
 resetQuery();
});


//Search functions


function search(){
	queryRange = 0;
	queryMin = -1;
	queryMax = -1;
	for (var i = 0;i<query.length;i++){
		if(query[i]!=-1){
			if(queryMin==-1)
				queryMin = i;
			queryMax = i;
		}

	}

	//console.log(symbol.key+" query goes from "+queryMin+"-"+queryMax);
	queryRange = queryMax-queryMin;


	//Not actually asynchronous so the spinning wheel is just telling me, the developer, that there was an error in executing the search.
	$("body").css("cursor","progress");
	var start = new Date().getTime();
	if(document.getElementById("queryMode").value==0){
		d3.select("#multiples").selectAll("svg").each(function(symbol){minDiff(symbol);});
	}
	else if(document.getElementById("queryMode").value==1){
		d3.select("#multiples").selectAll("svg").each(function(symbol){houghTransform(symbol);});
	}
	else{
		d3.select("#multiples").selectAll("svg").each(function(symbol){DTW(symbol);});
	}
	var end = new Date().getTime();
	var queryDuration = end-start;
	console.log("Query of size "+queryRange+" completed in "+queryDuration+" msecs");

	fromDiffToRank();
	hitColorScale.domain([d3.select("#multiples").select("svg:last-child").datum().bestHit,d3.select("#multiples").select("svg").datum().bestHit]);
	updateColors();
	result(document.getElementById("n").value);
	$("body").css("cursor","default");
	
}

function inRangeResults(symbol){
//Give me just the data values that fell into the window I found with the best match
	var inRangeValues = new Array();
	for(var i = 0;i<symbol.values.length;i++){
		if(rX(getTime(symbol.values[i]))>symbol.bestX - (queryRange/2.0) && rX(getTime(symbol.values[i]))<symbol.bestX+(queryRange/2.0)){
			inRangeValues.push(symbol.values[i]);
		}
	}
	return inRangeValues;
}

function result(n){
//Puts the top n results in the main pane. Tells the DB what query I made and on what dataset.
	
	//get rid of the old results
	d3.select("#results").selectAll("svg").remove();
	

	var topN = [];
	var rheight = 	((d3.select("#results").node().scrollHeight-70) / n) ;
	var cursvg;
	var curY;

var results = d3.select("#multiples").selectAll("svg").filter(function(d,i){ return d.rankOrder<n; });


//Okay, this next section is a little weird. Essentially, we have four cases:
//1a) We are doing juxtaposition of results (one graph per result)
//1b) We matched a REGION rather than a whole series, so we need to draw where we matched (and how well)
//2a) We are doing superposition of results (one graph for all n results)
//2b) Matched a REGION, so we draw where we matched.




	if(document.getElementById("resultMode").value==1){
		rheight = d3.select("#results").node().scrollHeight-30;
		cursvg = d3.select("#results").append("svg")
			.attr("width",rwidth)
			.attr("height",rheight);
		
		results.each(function(symbol,i){
			curY = d3.scale.linear()
            			.domain([d3.min(symbol.values, function(d){return getValue(d);}), d3.max(symbol.values, function(d) { return getValue(d); })])
		        	.range([rheight, 0]);
			
			var cindex;
			if(i%2==0){
				cindex=i;
			}
			else{
				cindex = 9-i;
			}
			cursvg.append("g")
				.append("path")
				.attr("fill-opacity",0)
				.attr("stroke-width",1.5)
				.attr("stroke",colorbrewer.RdBu[10][cindex])
				.attr("title",symbol.key)
				.attr("d", function(d) {
		       		return d3.svg.line()
            			.x(function(d) { return rX(getTime(d)); })
		       		.y(function(d) { return curY(getValue(d)); })
        			 (symbol.values);
     				 });
	
				
		if(document.getElementById("XPos").checked){
				if(document.getElementById("queryMode").value=="0"){	
					windowColorScale.domain([d3.select("#multiples").select("svg:last-child").datum().bestHit,0]);
				}
				else if(document.getElementById("queryMode").value=="1"){
					windowColorScale.domain([0,queryRange*voteScale]);
				}
				cursvg.append("g")
					.append("path")
						.attr("stroke",windowColorScale(symbol.bestHit))
						.attr("fill-opacity",0)
						.attr("stroke-width",3)
						.attr("d", function(d) {
		       				return d3.svg.line()
            					.x(function(d) { return rX(getTime(d)); })
				       		.y(function(d) { 
	
								return curY(getValue(d)); 
					
							})
	       					 (inRangeResults(symbol));
	     					 });
		}
			

			topN.push(symbol.key);
			});
		
	}
	else{
		results.each(function(symbol){
	//	console.log(symbol.key+" has a -MSE of "+symbol.bestHit+" over "+symbol.values.length+" points");
		curY = d3.scale.linear()
            		.domain([d3.min(symbol.values, function(d){return getValue(d);}), d3.max(symbol.values, function(d) { return getValue(d); })])
		        .range([rheight, 0]);

		cursvg = d3.select("#results").append("svg")
			.attr("width", rwidth)
      			.attr("height", rheight)

		cursvg.append("g")
			.append("path")
      			.attr("class", "area")
			.attr("fill","#333")
			.attr("d", function(d) {
		       		return d3.svg.area()
            			.x(function(d) { return rX(getTime(d)); })
		       		.y1(function(d) { return curY(getValue(d)); })
	        		.y0(rheight)
        			 (symbol.values);
     				 });



		
		if(document.getElementById("XPos").checked){
			if(document.getElementById("queryMode").value=="0"){	
				windowColorScale.domain([d3.select("#multiples").select("svg:last-child").datum().bestHit,0]);
			}
			else if(document.getElementById("queryMode").value=="1"){
				windowColorScale.domain([0,queryRange*voteScale]);
			}
			cursvg.append("g")
				.append("path")
			.attr("fill",windowColorScale(symbol.bestHit))
				.attr("d", function(d) {
		       			return d3.svg.area()
            				.x(function(d) { return rX(getTime(d)); })
			       		.y1(function(d) { 

							return curY(getValue(d)); 
				
					})
		        		.y0(rheight)
        				 (inRangeResults(symbol));
     					 });
		}

	
		 cursvg.append("text")
      		 	.attr("x", rwidth - 6)
		      	.attr("y",11)
			.style("stroke","#fff")
      			.style("stroke-width","2")
		      	.attr("fill","#000")
		      	.style("text-anchor", "end")
		      	.text(symbol.key);

		 cursvg.append("text")
      		 	.attr("x", rwidth - 6)
		      	.attr("y",11)
		      	.attr("fill","#000")
		      	.style("text-anchor", "end")
		      	.text(symbol.key);//function(symbol) { return symbol.key; });
	
		topN.push(symbol.key);
		
	
     	 });
	}

	//DB stuff.
	var queryString = "";
	for(var i = 0;i<query.length-1;i++){
		queryString+=(query[i]+",");
	}
	queryString+=query[query.length-1];

	var commentString = "Top "+n+" results: ";
	for(var i=0;i<n-1;i++){
		commentString+=topN[i]+",";
	}
	commentString+=topN[n-1];
	var datasetString = "<?php echo $_GET['data']; ?>";
	if(datasetString=="")
		datasetString="onemilliontop1k1800";

	sendData = {query: queryString, comments: commentString, mode: 0, dataset: datasetString, notes: "CHItesting"};


	$.ajax
	({
		type: "POST",
		url: 'db/queryOut.php',
		data: 'query='+sendData.query+"&comments="+sendData.comments+"&mode="+sendData.mode+"&dataset="+sendData.dataset+"&notes="+sendData.notes, 
		cache: false,
		success: function(){
		//	alert("Submitted Query");
		},
		error: function(xhr,ajaxOptions, thrownError){
			alert(thrownError);
		}
	});

}


function drawMode(){
	dMode = 0;
	d3.select("#drawBtn").select("img").attr("src","imgs/draw-active.png");
	d3.select("#eraseBtn").select("img").attr("src","imgs/erase.png");

}

function eraseMode(){
	dMode = 1;
	d3.select("#drawBtn").select("img").attr("src","imgs/draw.png");
	d3.select("#eraseBtn").select("img").attr("src","imgs/erase-active.png");

}

function houghMode(){
	searchMode = 1;
	d3.select("#exactBtn").select("img").attr("src","imgs/exactmatch.png");
	d3.select("#eventBtn").select("img").attr("src","imgs/eventmatch-active.png");
}

function fullMode(){
	searchMode = 0;
	d3.select("#exactBtn").select("img").attr("src","imgs/exactmatch-active.png");
	d3.select("#eventBtn").select("img").attr("src","imgs/eventmatch.png");
}

function fromDiffToRank(){
	//Sorts each time series based on how well it matched, then tells the series its own index.
	d3.selectAll("#multiples").selectAll("svg").sort(function(a,b){ 
		if(a.bestHit < b.bestHit)
			return 1;
		else if(a.bestHit > b.bestHit)
			return -1;
		else
			return 0;
	});
	d3.selectAll("#multiples").selectAll("svg").each(function(symbol,i){symbol.rankOrder = i;});
	
}

function resetDisplay(){
	d3.selectAll("#multiples").selectAll("svg").sort(function(a,b){
		if(a.key < b.key)
                	return -1;
                else if(a.key > b.key)
	                return 1;
                else
                        return 0;
        });
	d3.selectAll("#multiples").selectAll("svg").each(function(symbol,i){symbol.rankOrder = i;
		symbol.bestHit = NaN;
		symbol.bestX = 0;
	});
    hitColorScale.domain([-1,0]);
    updateColors();

}

function resetVotes(){
//Initializes our array of votes for Hough voting
	for(var i = 0;i<votes.length;i++){
		for(var j = 0;j<votes[i].length;j++){
			votes[i][j] = 0;
		}
	}
}

function makeVotes(){
//Creates our array of votes for Hough voting
	votes = new Array(Math.floor(searchCanvas.width*voteScale));
	for(var i = 0;i<votes.length;i++){
		votes[i] = new Array(Math.floor((searchCanvas.height + Math.floor((2*voteBuffer)*searchCanvas.height))*voteScale));
	}	
}


function houghTransform(symbol){
//Hough Transform
	resetVotes();
	var signal;
	var smoothed;
	if(document.getElementById("Noise").checked){
		smoothed = lpf(symbol.values);
	}
		signal = symbol.values;
//console.log(symbol.key);
	
	var yVoteScale = voteScale;
	if(document.getElementById("Amp").checked){
	//Deal with amplitude by just aliasing verticle accumulator cells, for now.
		yVoteScale/=2.0;
	}
	var curdiff = 0;
	var n = 0;
	var testX = 0;
	var vindex;
	var hindex;

	var curMax = 0;
	var curMaxX = 0;


	var startSearch = 0;
	var endSearch = signal.length;
	if(!document.getElementById("XPos").checked){
		var i = 0;
		testX = rX(getTime(signal[i]));
		while(testX<queryMin && i<signal.length-1){
			i++;
			testX = rX(getTime(signal[i]));
		}
		startSearch = i;
		var i = 0;
		testX = rX(getTime(signal[startSearch]));
		while(testX<queryMax && i<signal.length-1){
			i++;
			testX = rX(getTime(signal[i]));
		}
		endSearch = i;
	}
	var curVal;
	for(var i = startSearch;i<endSearch;i++){
		testX = Math.floor(rX(getTime(signal[i])));
			if(isNaN(query[testX])){
				console.log("query["+testX+"] is undefined");	
			}

			 for(var j=queryMin;j<queryMax;j++){
				if(query[j]!=-1){
					if(document.getElementById("Noise").checked){
						curVal = smoothed[i];				
					}
					else{
						curVal = getValue(signal[i]);
					}
					hindex = Math.floor((testX + ((queryRange/2.0)-(j-queryMin)))*voteScale);
					vindex = Math.floor((((searchCanvas.height/2.0) - query[j]) + rY(symbol.y(curVal))+(voteBuffer*searchCanvas.height))*yVoteScale); 	
					if(hindex>=0 && hindex<votes.length && vindex>0 && vindex<(votes[0].length)){
						votes[hindex][vindex]++;
								
							if(votes[hindex][vindex]>curMax){
							curMax = votes[hindex][vindex];
							curMaxX = hindex/voteScale; 
						}			
					}
					else{
						if(vindex<0 || vindex>votes[0].length){
							console.log("("+hindex+","+vindex+") is out of bounds, query[i]="+query[j]+", signal[i]="+rY(symbol.y(getValue(signal[i]))));
						}
					}
				}	

			}
		
	}
	
//	console.log(symbol.key+" votes:"+curMax);
	symbol.bestX = curMaxX;
	symbol.bestHit = curMax;
		
}

function DTW(symbol){
//Dynamic Time Warping
//There's no way this will be fast enough, and since we will likely have big temporal jumps (for alignment, if that's what the query calls for) then we can't use FastDTW, oh well.

	var signal = symbol.values;
	symbol.bestX = 0;
	if(document.getElementById("Noise").checked){
		var smoothed = lpf(signal);
	}
//The LOCATION of the best hit for DTW is a little tricky.
//I think it's probably where we stop making deletions or insertions and start making matches
	symbol.bestX = 0;
	symbol.bestHit = Number.MAX_VALUE;


	var DTWM = new Array(signal.length);
	for(var i = 0;i<DTWM.length;i++){
		DTWM[i] = new Array(query.length);
	}

	for(var i = 1;i<DTWM.length;i++){
		DTWM[i][0] = Number.MAX_VALUE;
	}

	for(var i = 1;i<DTWM[0].length;i++){
		DTWM[0][i] = Number.MAX_VALUE;
	}

	DTWM[0][0] = 0;

	var cost,icost,dcost,mcost,mincost;
	var curVal;
	var foundX = false;
	for(var i = 1;i<DTWM.length;i++){
		if(document.getElementById("Noise").checked){
			curVal = smoothed[i];
		}
		else{
			curVal = getValue(signal[i]);
		}
		for(var j = 1;j<DTWM[i].length;j++){
			cost = Math.pow(rY(symbol.y(curVal))-query[j],2);
			icost = DTWM[i-1][j];
			dcost = DTWM[i][j-1];
			mcost = DTWM[i-1][j-1];
			mincost = d3.min(new Array(icost,dcost,mcost));
			if(j>1 && !foundX && mincost==mcost){
				bestX = i;
				foundX = true;
			}
			DTWM[i][j] = cost + mincost;
		}
	}
	symbol.bestHit = -1*  DTWM[signal.length-1][query.length-1];

}

function minDiff(symbol){
//Mean Squared Error

//	console.log("Searching "+symbol.key+" which has "+symbol.values.length+" entries");	
	var signal = symbol.values;
	var smoothed = lpf(signal);

//actually calculate diffs
	var curdiff = 0;
	var n = 0;
	var testX = 0;
	var startSearch = 0;
	var endSearch = 1;
	symbol.bestHit = Number.MAX_VALUE;
	symbol.bestX = 0;
	var curVal;
	var queryShift = 0;
	//Since query is meant to be denser than target, we need to shift the query and try it in every position, if we are doing partial matches.
	if(document.getElementById("XPos").checked){
	//start by "shoving" the query all the way to the left
		startSearch = -queryMin;
	//end by "shoving" all the way to the right
		endSearch = (rwidth-queryMax);
		
	}
	for(var j = startSearch;j<endSearch;j++){
		curdiff = 0;
		n = 0;
		for(var i = 0;i<signal.length;i++){
			if(document.getElementById("Noise").checked){
				curVal = smoothed[i];
			}
			else{
				curVal = getValue(signal[i]);
			}
			testX = Math.round(rX(getTime(signal[i])));
			queryShift = testX - j;
			if(isNaN(query[queryShift])){
			//	console.log("query["+queryShift+"] is undefined");
			}
			if(!isNaN(query[queryShift]) && query[queryShift]!=-1){//queryShift > 0 && queryShift < query.length && query[queryShift]!=-1){
				n++;
				curdiff+=Math.pow(rY(symbol.y(curVal))-query[queryShift],2);
			}

		}
		//console.log(symbol.key+" MSE is "+curdiff+" over "+n+" points ("+curdiff/n+" normalized)");
		
		if(n>0 && (curdiff/n) < symbol.bestHit){
			symbol.bestHit = (curdiff/n);
			symbol.bestX = j + queryMin + (queryRange/2.0);
			symbol.bestN = n;
		}
		else{
			//	symbol.bestHit = -Number.MAX_VALUE;
		}
	}
	symbol.bestHit = -symbol.bestHit;
}

//Query Drawing
function mouseDown(m_event){
	if(!drawing){
		oldX = Math.round(m_event.pageX - bounds.left);
		oldY = Math.floor(searchCanvas.height - (bounds.bottom - m_event.pageY));
	}
	drawing = true;	
}

function mouseMove(m_event){
	var mx = Math.round(m_event.pageX - bounds.left);
	var my = Math.round(searchCanvas.height - (bounds.bottom - m_event.pageY));// -bounds.bottom;
	
	if(drawing){
		if(mx>searchCanvas.width){
			mx = searchCanvas.width;
		}
		else if(mx<0){
			mx = 0;
		}
		if(my<0){
			my = 0;
		}
		else if(my>searchCanvas.height){
			my = searchCanvas.height;
		}
		if(dMode==0){
			drawLine(mx,my);
		}
		else{
			eraseLine(mx,my);
		}
	
	}
//	console.log("("+mx+","+my+")");
	oldX = mx;
	oldY = my;
}


function drawLine(mx,my){
		var qpoint =  {x: mx, y: searchCanvas.height-my};
		sparseQuery.push(qpoint);

		context.fillStyle = "#333";	
		context.beginPath();
		context.moveTo(oldX,searchCanvas.height);
		context.lineTo(oldX,oldY);
		context.lineTo(mx,my);
		context.lineTo(mx,searchCanvas.height);
		context.closePath();
		context.fill();

		context.fillStyle = "#fff";
		context.beginPath();
		context.moveTo(oldX,0);
		context.lineTo(oldX,oldY);
		context.lineTo(mx,my);
		context.lineTo(mx,0);
		context.closePath();
		context.fill();
		var slope;
		if(mx>oldX){	
		 slope = (my-oldY)/(mx-oldX);
			for(var i = oldX;i<mx;i++){
				query[i] = searchCanvas.height-(((i-oldX)*slope)+oldY);
			//	query[i] = searchCanvas.height - my;
		//	console.log("Set query["+i+"]");
			}
		}
		else if(mx<oldX){
			slope = (oldY-my)/(oldX-mx);
			for(var i = mx;i<oldX;i++){
				query[i] = searchCanvas.height-(((i-mx)*slope)+my);
			}
		//	console.log("Set query["+i+"]");
		}
		else{
			query[mx] = searchCanvas.height-my;
		//	console.log("Set query["+mx+"]");
		}

}

function eraseLine(mx,my){

		context.fillStyle = "#fff";
		context.beginPath();
		context.moveTo(oldX,0);
		context.lineTo(oldX,searchCanvas.height);
		context.lineTo(mx,searchCanvas.height);
		context.lineTo(mx,0);
		context.closePath();
		context.fill();

		context.fillStyle = "#ff0000";
		context.beginPath();
		context.moveTo(oldX,searchCanvas.height);
		context.lineTo(oldX,searchCanvas.height-nanHeight);
		context.lineTo(mx,searchCanvas.height-nanHeight);
		context.lineTo(mx,searchCanvas.height);
		context.closePath();
		context.fill();

		if(mx>oldX){	
			for(var i = oldX;i<mx;i++){
				query[i] = -1;
			}
		}
		else if(mx<oldX){
			for(var i = mx;i<oldX;i++){
				query[i] = -1;
			}
		}
		else{
			query[mx] = -1;
		}
}




function mouseUp(m_event){
	drawing = false;
//	resetCanvas();
	oldX = -1;
	oldY = -1;
}

function mouseLeave(m_event){
	drawing = false;
}
function resetQuery(){
	context.clearRect(0,0,searchCanvas.width,searchCanvas.height);
	for(var i = 0;i<query.length;i++){
 		query[i] = -1;
	}
	sparseQuery = new Array();	
	
	context.fillStyle = "#ff0000";
	context.beginPath();
	context.moveTo(0,searchCanvas.height);
	context.lineTo(0,searchCanvas.height-nanHeight);
	context.lineTo(searchCanvas.width,searchCanvas.height-nanHeight);
	context.lineTo(searchCanvas.width,searchCanvas.height);
	context.closePath();
	context.fill();

}


function updateColors(){
 d3.select("#multiples").selectAll("svg").select("path").each(function(d){
		d3.select(this).attr("fill",hitColorScale(d.bestHit));
	});}


//Drag and drop


function touchInit(e){
	lastTouched = new Date.getTime();
//	alert("started");
}

function touchTransfer(e){
//	alert("ended");
	if(lastTouched==-1){
		lastTouched = new Date().getTime();
	}
	else{
		var now = new Date().getTime();
		var delta = now-lastTouched;
	//	alert(delta);
		if(delta<500 && delta>0){
			e.preventDefault();
			transferQuery(e);
		}
		lastTouched = now;
	}
//	alert("super ended");
}

function transferQuery(e){
	dragged = e.target;
	while(dragged.tagName!="svg")
	{
		dragged = dragged.parentNode;
	}
	dragged = d3.select(dragged);
	drawQuery(dragged.datum());
}

function drawQuery(symbol){
	resetQuery();	
	var nextX;
	var curX;
	var curY;
	var nextY;
	var slope;
	for(var i = 0;i<symbol.values.length-1;i++){
		curX = Math.floor(rX(getTime(symbol.values[i])));
		nextX = Math.floor(rX(getTime(symbol.values[i+1])));
		curY = searchCanvas.height - rY(symbol.y(getValue(symbol.values[i])));
		nextY = searchCanvas.height- rY(symbol.y(getValue(symbol.values[i+1])));

		context.fillStyle = "#333";	
		context.beginPath();
		context.moveTo(curX,searchCanvas.height);
		context.lineTo(curX,curY);
		context.lineTo(nextX,nextY);
		context.lineTo(nextX,searchCanvas.height);
		context.closePath();
		context.fill();

		context.fillStyle = "#fff";
		context.beginPath();
		context.moveTo(curX,0);
		context.lineTo(curX,curY);
		context.lineTo(nextX,nextY);
		context.lineTo(nextX,0);
		context.closePath();
		context.fill();

		slope = (nextY-curY)/(nextX-curX);
		for(var j = curX;j<nextX;j++){
			query[j] = searchCanvas.height - (curY + (slope*(j-curX)));
		}
		
		
	}
	

}

function setInvariants(){
	//MSE Does not afford: y position, amplitude, warp
	//Hough does not afford: unchecked y position, warp
	//DTW does not afford xposition, y position unchecked warp
	var currentMode = document.getElementById("queryMode").value;
	d3.selectAll("[type=checkbox]").each(function(){this.disabled="";});
	
	switch(currentMode){

	case "2":
		document.getElementById("XPos").checked=false;
		document.getElementById("XPos").disabled = "true";
		document.getElementById("YPos").checked=false;
		document.getElementById("YPos").disabled="true";
		document.getElementById("Warp").checked=true;
		document.getElementById("Warp").disabled="true";
		document.getElementById("Amp").checked=false;
		document.getElementById("Amp").disabled="true";
	break;

	case "1":
		document.getElementById("Warp").checked=false;
		document.getElementById("Warp").disabled = "true";
		document.getElementById("YPos").checked=true;
		document.getElementById("YPos").disabled= "true";
	break;

	case "0":
		document.getElementById("YPos").checked=false;
		document.getElementById("YPos").disabled="true";
		document.getElementById("Warp").checked=false;
		document.getElementById("Warp").disabled="true";
		document.getElementById("Amp").checked=false;
		document.getElementById("Amp").disabled="true";
	default:
	break;
	}

}

function lpf(series){
//Series is a dense series. Return a denoised one.
//Just do a weighted average rather than an actual sinc filter: no need to get too fancy
var smoothFactor = Math.max(5,0.1 * series.length);
var smoothed = Array(series.length);
//need a deep copy, luckily we just need value, not date

	for(var i = 0;i<smoothed.length;i++){
		smoothed[i] = getValue(series[i]);
	}

	var curAvg = getValue(series[0]);
	for(var i = 1;i<smoothed.length;i++){
		curAvg += (getValue(series[i]) - curAvg)/smoothFactor;
		smoothed[i] = curAvg;
	}
	return smoothed;

}
</script>
